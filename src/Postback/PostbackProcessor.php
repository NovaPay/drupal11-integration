<?php

declare(strict_types=1);

namespace Drupal\commerce_novapay\Postback;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\commerce_novapay\Credential\NovaPayMode;
use Drupal\commerce_novapay\Api\NovaPayApiClientInterface;
use Drupal\commerce_novapay\Api\Dto\Request\GetStatusRequest;
use Drupal\commerce_novapay\Exception\InvalidPostbackException;
use Drupal\commerce_novapay\Exception\PostbackProcessingException;
use Drupal\commerce_novapay\Payment\PendingOperation;
use Drupal\commerce_novapay\Payment\RefundStatusEvidence;
use Drupal\commerce_novapay\Payment\PaymentOrderLockInterface;
use Drupal\commerce_novapay\Payment\SessionLockName;
use Drupal\commerce_novapay\Payment\CaptureOperationTracker;
use Drupal\commerce_novapay\Payment\RefundOperationManagerInterface;
use Drupal\commerce_novapay\Postback\Dto\NormalizedPostbackEvent;
use Drupal\commerce_novapay\Postback\Parser\PostbackParserInterface;
use Drupal\commerce_novapay\Runtime\RuntimeConfigurationProviderInterface;
use Drupal\commerce_novapay\Signature\SandboxLegacyVerifierInterface;
use Drupal\commerce_novapay\Signature\VerifierInterface;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_order\OrderStorageInterface;
use Drupal\commerce_payment\Entity\PaymentGatewayInterface;
use Drupal\commerce_payment\Entity\PaymentInterface;
use Drupal\commerce_payment\PaymentStorageInterface;

/**
 * Implements the signature-first NovaPay postback processing boundary.
 */
final class PostbackProcessor implements PostbackProcessorInterface {

  public function __construct(
    private readonly VerifierInterface $verifier,
    private readonly SandboxLegacyVerifierInterface $sandbox_legacy_verifier,
    private readonly PostbackParserInterface $parser,
    private readonly EntityTypeManagerInterface $entity_type_manager,
    private readonly PaymentStatusMapperInterface $status_mapper,
    private readonly PostbackEventRepositoryInterface $event_repository,
    private readonly LockBackendInterface $lock,
    private readonly RefundOperationManagerInterface $refund_manager,
    private readonly NovaPayApiClientInterface $api_client,
    private readonly PaymentOrderLockInterface $orderLock,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function process(
    PaymentGatewayInterface $gateway,
    RuntimeConfigurationProviderInterface $gateway_plugin,
    #[\SensitiveParameter]
    string $raw_body,
    #[\SensitiveParameter]
    string $signature,
  ): PostbackResult {
    $configuration = $gateway_plugin->getRuntimeConfiguration();
    $credentials = $configuration->getCredentials();
    $detailed_logging = $configuration->getProfile()->isLoggingEnabled();
    $public_key = $credentials->getPublicKeyPem();
    $verified = $this->verifier->verify($raw_body, $signature, $public_key);
    if (
      !$verified
      && $credentials->getMode() === NovaPayMode::Test
    ) {
      $verified = $this->sandbox_legacy_verifier->verify(
        $raw_body,
        $signature,
        $public_key,
      );
    }
    if (!$verified) {
      return PostbackResult::invalidSignature($detailed_logging);
    }

    $diagnostics = [];
    try {
      $parsed = $this->parser->parse($raw_body);
    }
    catch (InvalidPostbackException) {
      return PostbackResult::invalidPayload($detailed_logging);
    }
    if (
      $parsed->getVersion() === PostbackVersion::V1
      && $credentials->getMode() !== NovaPayMode::Test
    ) {
      return PostbackResult::invalidPayload($detailed_logging);
    }
    $event = $parsed->getEvent();
    $version = $parsed->getVersion();
    $lock_name = SessionLockName::fromSessionId($event->getSessionId());
    if (!$this->lock->acquire($lock_name)) {
      $this->lock->wait($lock_name);
      if (!$this->lock->acquire($lock_name)) {
        throw PostbackProcessingException::serializationFailed();
      }
    }

    $order_lock = NULL;
    try {
      $payment = $this->findPayment($gateway, $event);
      if ($payment !== NULL) {
        $order_lock = $this->orderLock->acquire($payment);
      }
      $resolved_event = $event;
      $event_key = hash('sha256', $raw_body);
      if ($version === PostbackVersion::V1 && $event->getStatus() === NovaPayStatus::Voided
        && $payment !== NULL
        && ($this->refund_manager->hasPendingPartialRefund($payment)
          || $payment->getState()->getId() === 'partially_refunded')) {
        // Flat V1 bodies can repeat: verify amounts before duplicate detection.
        $response = $this->api_client->getStatus($gateway_plugin, new GetStatusRequest($event->getSessionId()));
        $resolved_event = RefundStatusEvidence::resolve($payment, $response);
        if ($resolved_event === NULL) {
          throw new \UnexpectedValueException('NovaPay refund amount is not confirmed for this operation.');
        }
        if ($resolved_event->getStatus() !== NovaPayStatus::Voided) {
          $event_key = hash('sha256', $raw_body . "\0refund:" . $payment->id() . ':' . $resolved_event->getRefundedAmount());
        }
      }
      $outcome = $this->event_repository->processOnce(
        $event_key,
        $event->getSessionId(),
        (string) $gateway->id(),
        $event->getStatus(),
        function () use ($gateway_plugin, $event, $resolved_event, $event_key, $detailed_logging, $payment, &$diagnostics): PostbackOutcome {
          return $this->applyEvent(
            $gateway_plugin,
            $resolved_event,
            $event_key,
            $detailed_logging,
            $payment,
            $diagnostics,
            $event->getStatus(),
          );
        },
      );
      if ($outcome === NULL && $payment !== NULL
        && $version === PostbackVersion::V1
        && $resolved_event->getStatus() === NovaPayStatus::Paid) {
        // Older versions journaled partial V1 voids and left access open.
        // Exact evidence was checked above; repair only the terminal marker.
        $this->preserveTerminalRemoteState($payment, $event->getStatus());
      }
    }
    finally {
      if ($order_lock !== NULL) {
        $this->orderLock->release($order_lock);
      }
      $this->lock->release($lock_name);
    }

    return PostbackResult::forEvent(
      $outcome ?? PostbackOutcome::Duplicate,
      $parsed->getVersion(),
      $event->getStatus(),
      $diagnostics,
      $detailed_logging,
    );
  }

  /**
   * Applies a verified event while the caller holds the session lock.
   *
   * @param \Drupal\commerce_novapay\Runtime\RuntimeConfigurationProviderInterface $gateway_plugin
   *   The already verified callback gateway runtime.
   * @param \Drupal\commerce_novapay\Postback\Dto\NormalizedPostbackEvent $event
   *   The verified normalized event.
   * @param string $event_key
   *   The event journal key.
   * @param bool $detailed_logging
   *   Whether sanitized diagnostics are enabled.
   * @param \Drupal\commerce_payment\Entity\PaymentInterface|null $payment
   *   The session-locked payment with its freshly reloaded parent order.
   * @param array<string, string> $diagnostics
   *   The resulting safe diagnostic context.
   * @param \Drupal\commerce_novapay\Postback\NovaPayStatus $received_status
   *   The original signed status before refund amount resolution.
   */
  private function applyEvent(
    RuntimeConfigurationProviderInterface $gateway_plugin,
    NormalizedPostbackEvent $event,
    string $event_key,
    bool $detailed_logging,
    ?PaymentInterface $payment,
    array &$diagnostics,
    NovaPayStatus $received_status,
  ): PostbackOutcome {
    if ($payment === NULL) {
      return PostbackOutcome::UnknownPayment;
    }

    $operation_id = $event->getCaptureOperationId();
    if (CaptureOperationTracker::requiresLookup($payment, $event->getStatus(), $operation_id)) {
      // Do not call the status manager here: it acquires the same session lock.
      // This endpoint only reads status and never resubmits capture or refund.
      $response = $this->api_client->getStatus($gateway_plugin, new GetStatusRequest($event->getSessionId()));
      if ($response->getSessionId() !== $event->getSessionId()
        || !in_array($response->getStatus(), [NovaPayStatus::Paid, NovaPayStatus::HoldConfirmed], TRUE)) {
        throw new \UnexpectedValueException('NovaPay has not confirmed this capture session.');
      }
      $operation_id = $response->getCaptureOperationId();
    }
    $capture_changed = CaptureOperationTracker::apply($payment, $event->getStatus(), $operation_id);

    $pending_refund = $this->hasConfirmablePendingRefund(
      $payment,
      $event->getStatus(),
    );
    $this->refund_manager->confirm($payment, $event, $event_key);
    $status_applied = $this->status_mapper->apply(
      $payment,
      $event->getStatus(),
    );
    if ($event->getStatus() !== $received_status
      && $this->preserveTerminalRemoteState($payment, $received_status)) {
      $status_applied = TRUE;
    }
    if ($capture_changed && !$status_applied) {
      $payment->save();
    }
    if ($status_applied || $pending_refund || $capture_changed) {
      if ($detailed_logging) {
        $diagnostics = $this->buildPaymentDiagnostics($payment);
        $diagnostics['payment_completed'] = $payment->isCompleted() ? 'yes' : 'no';
        try {
          $order = $payment->getOrder();
          $diagnostics['order_paid'] = $order instanceof OrderInterface
            ? ($order->isPaid() ? 'yes' : 'no')
            : 'unavailable';
        }
        catch (\Throwable) {
          // Diagnostic failures must not roll back a confirmed payment.
          $diagnostics['order_paid'] = 'unavailable';
        }
      }
      return PostbackOutcome::Applied;
    }

    if ($detailed_logging) {
      $diagnostics = ['reason' => 'no_permitted_payment_mutation']
        + $this->buildPaymentDiagnostics($payment);
    }
    return PostbackOutcome::Ignored;
  }

  /**
   * Keeps terminal operation access separate from the confirmed refund amount.
   */
  private function preserveTerminalRemoteState(PaymentInterface $payment, NovaPayStatus $received_status): bool {
    if ($received_status !== NovaPayStatus::Voided
      || $payment->getState()->getId() !== 'partially_refunded'
      || $payment->getRemoteState() === NovaPayStatus::Voided->value) {
      return FALSE;
    }
    // The caller holds session/order locks; SQL entity save is transactional.
    $payment->setRemoteState(NovaPayStatus::Voided->value);
    $payment->save();
    return TRUE;
  }

  /**
   * Builds fixed-value payment state diagnostics for a valid callback.
   *
   * This deliberately excludes session/order/payment identifiers, raw payload,
   * signature and any customer or payment data.
   *
   * @return array<string, string>
   *   Sanitized diagnostic context.
   */
  private function buildPaymentDiagnostics(PaymentInterface $payment): array {
    $state = $payment->getState()->getId();
    $known_states = [
      'pending',
      'authorization',
      'completed',
      'partially_refunded',
      'refunded',
      'authorization_voided',
      'expired',
      'failed',
    ];
    $remote_state = $payment->getRemoteState();
    $pending_operation = $payment->hasField('novapay_pending_operation')
      ? $payment->get('novapay_pending_operation')->getString()
      : '';
    $pending_operation = PendingOperation::tryFrom($pending_operation);

    return [
      'payment_state' => in_array($state, $known_states, TRUE)
        ? $state
        : 'other',
      'remote_state' => is_string($remote_state)
        && NovaPayStatus::tryFrom($remote_state) !== NULL
        ? $remote_state
        : 'none_or_other',
      'pending_operation' => $pending_operation instanceof PendingOperation
        ? $pending_operation->value
        : 'none_or_other',
    ];
  }

  /**
   * Finds one unambiguous payment by session, then safe external-ID fallback.
   */
  private function findPayment(
    PaymentGatewayInterface $gateway,
    NormalizedPostbackEvent $event,
  ): ?PaymentInterface {
    $payment_storage = $this->entity_type_manager
      ->getStorage('commerce_payment');
    if (!$payment_storage instanceof PaymentStorageInterface) {
      throw PostbackProcessingException::paymentStorageUnavailable();
    }

    $payment = $this->getUniquePayment($payment_storage->loadByProperties([
      'payment_gateway' => $gateway->id(),
      'remote_id' => $event->getSessionId(),
    ]));
    if ($payment !== NULL) {
      return $payment;
    }

    return $this->findPaymentByExternalIds(
      $payment_storage,
      $gateway,
      $event,
    );
  }

  /**
   * Resolves a unique same-gateway payment from external order references.
   */
  private function findPaymentByExternalIds(
    PaymentStorageInterface $payment_storage,
    PaymentGatewayInterface $gateway,
    NormalizedPostbackEvent $event,
  ): ?PaymentInterface {
    $order_storage = $this->entity_type_manager->getStorage('commerce_order');
    if (!$order_storage instanceof OrderStorageInterface) {
      throw PostbackProcessingException::orderStorageUnavailable();
    }

    $candidates = [];
    foreach ($event->getExternalIds() as $external_id) {
      $orders = $order_storage->loadByProperties([
        'order_number' => $external_id,
      ]);
      if (preg_match('/^[1-9][0-9]*$/D', $external_id) === 1) {
        $order = $order_storage->load($external_id);
        if ($order instanceof OrderInterface) {
          $orders[$order->id()] = $order;
        }
      }

      foreach ($orders as $order) {
        if (!$order instanceof OrderInterface) {
          continue;
        }
        $payments = $payment_storage->loadByProperties([
          'payment_gateway' => $gateway->id(),
          'order_id' => $order->id(),
        ]);
        $candidates = array_replace(
          $candidates,
          $this->getSessionCandidates($payments, $event),
        );
      }
    }

    return $this->getUniquePayment($candidates);
  }

  /**
   * Filters same-gateway payments to those compatible with the session.
   *
   * @param array<array-key, mixed> $payments
   *   Payments loaded for an external order reference.
   * @param \Drupal\commerce_novapay\Postback\Dto\NormalizedPostbackEvent $event
   *   The verified event.
   *
   * @return array<string, \Drupal\commerce_payment\Entity\PaymentInterface>
   *   Candidates indexed by payment ID.
   */
  private function getSessionCandidates(
    array $payments,
    NormalizedPostbackEvent $event,
  ): array {
    $candidates = [];
    foreach ($payments as $payment) {
      if (!$payment instanceof PaymentInterface) {
        continue;
      }
      $remote_id = $payment->getRemoteId();
      if (
        is_string($remote_id)
        && $remote_id !== ''
        && $remote_id !== $event->getSessionId()
      ) {
        continue;
      }
      $candidates[(string) $payment->id()] = $payment;
    }
    return $candidates;
  }

  /**
   * Returns a payment only when the candidate set is unambiguous.
   *
   * @param array<array-key, mixed> $candidates
   *   Loaded payment candidates.
   */
  private function getUniquePayment(array $candidates): ?PaymentInterface {
    $payments = array_values(array_filter(
      $candidates,
      static fn (mixed $candidate): bool =>
        $candidate instanceof PaymentInterface,
    ));

    return count($payments) === 1 ? $payments[0] : NULL;
  }

  /**
   * Checks whether refund confirmation may mutate the payment before mapping.
   */
  private function hasConfirmablePendingRefund(
    PaymentInterface $payment,
    NovaPayStatus $status,
  ): bool {
    return in_array(
      $status,
      [
        NovaPayStatus::ProcessingVoid,
        NovaPayStatus::Paid,
        NovaPayStatus::Voided,
      ],
      TRUE,
    )
      && $payment->hasField('novapay_pending_operation')
      && $payment->get('novapay_pending_operation')->getString()
        === PendingOperation::Refund->value;
  }

}
