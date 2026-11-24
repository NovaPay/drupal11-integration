<?php

declare(strict_types=1);

namespace Drupal\commerce_novapay\Payment;

use Drupal\Core\Lock\LockBackendInterface;
use Drupal\commerce_novapay\Api\Dto\Request\GetStatusRequest;
use Drupal\commerce_novapay\Api\Dto\Request\VoidRequest;
use Drupal\commerce_novapay\Api\NovaPayApiClientInterface;
use Drupal\commerce_novapay\Exception\ApiFatalException;
use Drupal\commerce_novapay\Exception\ApiTransportException;
use Drupal\commerce_novapay\Exception\ApiUnexpectedResponseException;
use Drupal\commerce_novapay\Exception\NovaPayApiException;
use Drupal\commerce_novapay\Postback\Dto\NormalizedPostbackEvent;
use Drupal\commerce_novapay\Postback\NovaPayStatus;
use Drupal\commerce_novapay\Runtime\RuntimeConfigurationProviderInterface;
use Drupal\commerce_payment\Entity\PaymentInterface;
use Drupal\commerce_payment\Exception\InvalidRequestException;
use Drupal\commerce_payment\Exception\PaymentGatewayException;
use Drupal\commerce_price\Price;

/**
 * Coordinates serialized NovaPay refund submission and status reconciliation.
 */
final class RefundOperationManager implements RefundOperationManagerInterface {

  use RefundFailureMessageTrait;

  private const float LOCK_TIMEOUT_SECONDS = 30.0;

  public function __construct(
    private readonly RefundPaymentStorage $payments,
    private readonly NovaPayApiClientInterface $api_client,
    private readonly LockBackendInterface $lock,
    private readonly RefundSelectionBuilder $selections,
    private readonly PendingRefundStorage $pending,
    private readonly RefundConfirmationManager $confirmation,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function canRefund(PaymentInterface $payment): bool {
    try {
      if ($payment->getRemoteState() === 'voided') {
        return FALSE;
      }
      return in_array(
        $payment->getState()->getId(),
        ['completed', 'partially_refunded'],
        TRUE,
      )
        && $payment->hasField('novapay_pending_operation')
        && $payment->get('novapay_pending_operation')->isEmpty()
        && $payment->getBalance() instanceof Price
        && $payment->getBalance()->isPositive()
        && $payment->getBalance()->getCurrencyCode() === 'UAH';
    }
    catch (\Throwable) {
      return FALSE;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function canCheckStatus(PaymentInterface $payment): bool {
    return $this->pending->canCheckStatus($payment);
  }

  /**
   * {@inheritdoc}
   */
  public function hasPendingPartialRefund(PaymentInterface $payment): bool {
    if (!$this->canCheckStatus($payment)) {
      return FALSE;
    }

    try {
      [$is_full] = $this->pending->decodePending($payment);
      return !$is_full;
    }
    catch (\Throwable) {
      return FALSE;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getRefundableItems(PaymentInterface $payment): array {
    return $this->selections->getRefundableItems($payment);
  }

  /**
   * {@inheritdoc}
   */
  public function getAllRefundItems(PaymentInterface $payment): array {
    return $this->selections->getAllRefundItems($payment);
  }

  /**
   * {@inheritdoc}
   */
  public function confirm(
    PaymentInterface $payment,
    NormalizedPostbackEvent $event,
    string $event_key,
  ): void {
    $this->confirmation->confirm($payment, $event, $event_key);
  }

  /**
   * {@inheritdoc}
   */
  public function refund(
    PaymentInterface $payment,
    RuntimeConfigurationProviderInterface $gateway,
    array $quantities = [],
    array $amounts = [],
  ): void {
    $this->submitRefund($payment, $gateway, $quantities, NULL, $amounts);
  }

  /**
   * {@inheritdoc}
   */
  public function refundAmount(
    PaymentInterface $payment,
    RuntimeConfigurationProviderInterface $gateway,
    Price|string $amount,
  ): void {
    $this->submitRefund($payment, $gateway, [], $amount);
  }

  /**
   * Validates either intent against a fresh payment under the session lock.
   *
   * @param \Drupal\commerce_payment\Entity\PaymentInterface $payment
   *   The NovaPay payment.
   * @param \Drupal\commerce_novapay\Runtime\RuntimeConfigurationProviderInterface $gateway
   *   The runtime-aware gateway.
   * @param array<int, string> $quantities
   *   Selected item quantities.
   * @param \Drupal\commerce_price\Price|string|null $requested_amount
   *   Monetary intent, or NULL for the legacy full refund.
   * @param array<int, string> $amounts
   *   Optional explicit amounts keyed by order item ID.
   */
  private function submitRefund(
    PaymentInterface $payment,
    RuntimeConfigurationProviderInterface $gateway,
    array $quantities,
    Price|string|null $requested_amount = NULL,
    array $amounts = [],
  ): void {
    $payment_id = $this->payments->requirePaymentId($payment);
    $session_id = trim((string) $payment->getRemoteId());
    if ($session_id === '') {
      throw InvalidRequestException::createForPayment(
        $payment,
        (string) $this->t('The NovaPay session ID is unavailable.'),
      );
    }

    $lock_name = SessionLockName::fromSessionId($session_id);
    if (!$this->lock->acquire($lock_name, self::LOCK_TIMEOUT_SECONDS)) {
      throw PaymentGatewayException::createForPayment(
        $payment,
        (string) $this->t('Another NovaPay operation is already being processed.'),
      );
    }

    try {
      $current = $this->payments->loadCurrentPayment($payment_id, $payment);
      if (!$this->canRefund($current)) {
        throw InvalidRequestException::createForPayment(
          $current,
          (string) $this->t('This payment is not available for another NovaPay refund.'),
        );
      }
      if (trim((string) $current->getRemoteId()) !== $session_id) {
        throw InvalidRequestException::createForPayment(
          $current,
          (string) $this->t('The NovaPay session ID changed while preparing the refund.'),
        );
      }

      // The standard Commerce full-refund call is a monetary intent too.
      if ($amounts !== []) {
        $items = $this->selections->buildAmountSelection($current, $quantities, $amounts);
      }
      elseif ($requested_amount !== NULL || $quantities === []) {
        $amount = $this->selections->validateAmount($requested_amount ?? $current->getBalance(), $current);
        $items = [new RefundSelection(NULL, '0', $amount)];
      }
      else {
        [$items, $all_items] = $this->selections->buildPartialSelection($current, $quantities);
        if ($all_items) {
          // Full intent includes any remaining paid allocation, but never the
          // uncaptured portion of a hold or an amount already refunded.
          $balance = $current->getBalance();
          $item_total = new Price('0', $balance->getCurrencyCode());
          foreach ($items as $item) {
            $item_total = $item_total->add($item->getAmount());
          }
          if (!$item_total->equals($balance)) {
            $items = [new RefundSelection(NULL, '0', $balance)];
          }
        }
      }
      $amount = $this->selections->validateAmount($this->selections->getSelectionTotal($items, $current), $current);
      $is_full = $amount->equals($current->getBalance());
      $operation_id = trim(
        $current->get('novapay_operation_id')->getString(),
      );
      if (!$is_full && $operation_id === '') {
        throw InvalidRequestException::createForPayment(
          $current,
          (string) $this->t('A partial refund requires the NovaPay operation ID.'),
        );
      }
      $operations = $is_full ? [] : [[
        'id' => $operation_id,
        'refund_amount' => $amount->getNumber(),
      ]];
      $this->pending->markPending($current, $is_full, $items, $amount);

      $this->sendRefund($current, $gateway, new VoidRequest($session_id, $operations));
    }
    finally {
      $this->lock->release($lock_name);
    }
  }

  /**
   * Sends the financial command once and preserves uncertain pending intents.
   */
  private function sendRefund(
    PaymentInterface $current,
    RuntimeConfigurationProviderInterface $gateway,
    VoidRequest $request,
  ): void {
    try {
      $this->api_client->voidPayment(
        $gateway,
        $request,
      );
    }
    catch (\Throwable $exception) {
      if (!$this->hasUncertainOutcome($exception)) {
        $this->pending->clearPending($current);
        $current->save();
      }
      throw PaymentGatewayException::createForPayment(
        $current,
        $this->hasUncertainOutcome($exception)
          ? trim(($this->getRefundFailureMessage($exception) ?? '') . ' ' . (string) $this->t('The NovaPay response is uncertain. Wait for postback confirmation before retrying.'))
          : ($this->getRefundFailureMessage($exception) ?? (string) $this->t('NovaPay rejected the refund request.')),
        previous: $exception,
      );
    }
  }

  /**
   * {@inheritdoc}
   */
  public function checkStatus(
    PaymentInterface $payment,
    RuntimeConfigurationProviderInterface $gateway,
  ): RefundStatusCheckResult {
    $payment_id = $this->payments->requirePaymentId($payment);
    $session_id = trim((string) $payment->getRemoteId());
    if ($session_id === '') {
      throw InvalidRequestException::createForPayment(
        $payment,
        (string) $this->t('The NovaPay session ID is unavailable.'),
      );
    }

    $lock_name = SessionLockName::fromSessionId($session_id);
    if (!$this->lock->acquire($lock_name, self::LOCK_TIMEOUT_SECONDS)) {
      throw PaymentGatewayException::createForPayment(
        $payment,
        (string) $this->t('Another NovaPay operation is already being processed.'),
      );
    }

    try {
      $current = $this->payments->loadCurrentPayment($payment_id, $payment);
      if (!$this->canCheckStatus($current)) {
        throw InvalidRequestException::createForPayment(
          $current,
          (string) $this->t('This payment has no pending NovaPay refund.'),
        );
      }
      if (trim((string) $current->getRemoteId()) !== $session_id) {
        throw InvalidRequestException::createForPayment(
          $current,
          (string) $this->t('The NovaPay session ID changed before the status check.'),
        );
      }

      try {
        $response = $this->api_client->getStatus(
          $gateway,
          new GetStatusRequest($session_id),
        );
      }
      catch (\Throwable $exception) {
        throw PaymentGatewayException::createForPayment(
          $current,
          (string) $this->t('The NovaPay refund status could not be checked.'),
          previous: $exception,
        );
      }
      if ($response->getSessionId() !== $session_id) {
        throw PaymentGatewayException::createForPayment(
          $current,
          (string) $this->t('NovaPay returned an unexpected session status.'),
        );
      }

      [$is_full] = $this->pending->decodePending($current);
      $status = $response->getStatus();
      $refunded_amount = NULL;
      $operation_id = trim(
        $current->get('novapay_operation_id')->getString(),
      );
      if ($operation_id !== '') {
        $refunded_amount = $response->getRefundedAmount($operation_id);
      }
      elseif (!$is_full && $status !== NovaPayStatus::Voided) {
        throw InvalidRequestException::createForPayment(
          $current,
          (string) $this->t('The NovaPay operation ID is unavailable.'),
        );
      }

      if (!$is_full) {
        $resolved = RefundStatusEvidence::resolve($current, $response);
        if ($resolved === NULL) {
          return RefundStatusCheckResult::Pending;
        }
        $status = $resolved->getStatus();
      }

      [$may_confirm, $confirmation_status] = $this->confirmation->getStatusConfirmation(
        $current,
        $is_full,
        $status,
        $refunded_amount,
      );
      if (!$may_confirm) {
        return RefundStatusCheckResult::Pending;
      }

      $event = NormalizedPostbackEvent::fromValues(
        $session_id,
        $confirmation_status->value,
        [],
        $refunded_amount === NULL ? [] : [$refunded_amount],
      );
      $event_key = hash(
        'sha256',
        implode("\0", [
          'status-check',
          $session_id,
          $confirmation_status->value,
          $refunded_amount ?? '',
        ]),
      );
      return $this->confirmation->reconcile(
        $current,
        $event,
        $event_key,
        $is_full,
        $status,
      );
    }
    finally {
      $this->lock->release($lock_name);
    }
  }

  /**
   * Returns whether NovaPay might have accepted the financial command.
   */
  private function hasUncertainOutcome(\Throwable $exception): bool {
    if (
      $exception instanceof ApiTransportException
      || $exception instanceof ApiFatalException
    ) {
      return TRUE;
    }
    if (
      $exception instanceof NovaPayApiException
      && ($exception->getHttpStatus() ?? 0) >= 500
    ) {
      return TRUE;
    }
    if ($exception instanceof ApiUnexpectedResponseException) {
      $status = $exception->getHttpStatus();
      return is_int($status) && $status >= 200 && $status < 300;
    }

    return FALSE;
  }

}
