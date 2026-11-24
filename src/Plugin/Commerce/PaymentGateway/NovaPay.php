<?php

declare(strict_types=1);

namespace Drupal\commerce_novapay\Plugin\Commerce\PaymentGateway;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\commerce_novapay\Logging\NovaPayLoggerInterface;
use Drupal\commerce_novapay\Payment\AuthorizationOperationManagerInterface;
use Drupal\commerce_novapay\Payment\PaymentStatusCheckManagerInterface;
use Drupal\commerce_novapay\Payment\PaymentStatusCheckResult;
use Drupal\commerce_novapay\Payment\RefundOperationManagerInterface;
use Drupal\commerce_novapay\Payment\RefundStatusCheckResult;
use Drupal\commerce_novapay\Payment\SupportsItemRefundsInterface;
use Drupal\commerce_novapay\Payment\SupportsPaymentStatusChecksInterface;
use Drupal\commerce_novapay\PluginForm\NovaPayCaptureForm;
use Drupal\commerce_novapay\PluginForm\NovaPayPaymentOffsiteForm;
use Drupal\commerce_novapay\PluginForm\NovaPayPaymentStatusForm;
use Drupal\commerce_novapay\PluginForm\NovaPayRefundForm;
use Drupal\commerce_novapay\PluginForm\NovaPayRefundStatusForm;
use Drupal\commerce_novapay\PluginForm\NovaPayVoidForm;
use Drupal\commerce_novapay\Postback\PostbackOutcome;
use Drupal\commerce_novapay\Postback\PostbackProcessorInterface;
use Drupal\commerce_payment\Attribute\CommercePaymentGateway;
use Drupal\commerce_payment\Entity\PaymentInterface;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\SupportsAuthorizationsInterface;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\SupportsRefundsInterface;
use Drupal\commerce_price\Price;
use Drupal\commerce_order\Entity\OrderInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Provides the NovaPay off-site payment gateway.
 */
#[CommercePaymentGateway(
  id: 'novapay',
  label: new TranslatableMarkup('NovaPay'),
  display_label: new TranslatableMarkup('NovaPay'),
  modes: [
    'n/a' => new TranslatableMarkup('Environment-local'),
  ],
  forms: [
    'offsite-payment' => NovaPayPaymentOffsiteForm::class,
    'capture-payment' => NovaPayCaptureForm::class,
    'refund-payment' => NovaPayRefundForm::class,
    'check-refund-status' => NovaPayRefundStatusForm::class,
    'check-payment-status' => NovaPayPaymentStatusForm::class,
    'void-payment' => NovaPayVoidForm::class,
  ],
  payment_type: 'novapay_payment',
  payment_method_types: ['credit_card'],
  requires_billing_information: FALSE,
)]
final class NovaPay extends NovaPayConfigurationGatewayBase implements SupportsAuthorizationsInterface, SupportsItemRefundsInterface, SupportsPaymentStatusChecksInterface, SupportsRefundsInterface {

  /**
   * The signature-first NovaPay postback processor.
   */
  private PostbackProcessorInterface $postbackProcessor;

  /**
   * The sanitized NovaPay logger channel.
   */
  private NovaPayLoggerInterface $logger;

  /**
   * The serialized NovaPay capture/void operation manager.
   */
  private AuthorizationOperationManagerInterface $authorizationOperationManager;

  /**
   * The read-only NovaPay payment status reconciliation manager.
   */
  private PaymentStatusCheckManagerInterface $paymentStatusCheckManager;

  /**
   * The serialized NovaPay refund manager and confirmed item ledger.
   */
  private RefundOperationManagerInterface $refundOperationManager;

  /**
   * {@inheritdoc}
   *
   * @param \Symfony\Component\DependencyInjection\ContainerInterface $container
   *   The service container.
   * @param array<string, mixed> $configuration
   *   The plugin configuration.
   * @param string $plugin_id
   *   The plugin ID.
   * @param array<string, mixed> $plugin_definition
   *   The plugin definition.
   */
  public static function create(
    ContainerInterface $container,
    array $configuration,
    $plugin_id,
    $plugin_definition,
  ) {
    $instance = parent::create(
      $container,
      $configuration,
      $plugin_id,
      $plugin_definition,
    );
    $instance->postbackProcessor = $container->get(
      'commerce_novapay.postback.processor',
    );
    $instance->logger = $container->get(
      'commerce_novapay.logger',
    );
    $instance->authorizationOperationManager = $container->get(
      'commerce_novapay.authorization_operation_manager',
    );
    $instance->paymentStatusCheckManager = $container->get(
      'commerce_novapay.payment_status_check_manager',
    );
    $instance->refundOperationManager = $container->get(
      'commerce_novapay.refund_operation_manager',
    );

    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function canCapturePayment(PaymentInterface $payment): bool {
    return $this->authorizationOperationManager->canCapture($payment);
  }

  /**
   * {@inheritdoc}
   */
  public function capturePayment(
    PaymentInterface $payment,
    ?Price $amount = NULL,
  ): void {
    $this->authorizationOperationManager->capture($payment, $this, $amount);
  }

  /**
   * {@inheritdoc}
   */
  public function canVoidPayment(PaymentInterface $payment): bool {
    if ($payment->getRemoteState() === 'voided'
      || in_array($payment->getState()->getId(), ['refunded', 'authorization_voided'], TRUE)) {
      return FALSE;
    }
    return $this->authorizationOperationManager->canVoid($payment);
  }

  /**
   * {@inheritdoc}
   */
  public function voidPayment(PaymentInterface $payment): void {
    $this->authorizationOperationManager->void($payment, $this);
  }

  /**
   * {@inheritdoc}
   */
  public function canRefundPayment(PaymentInterface $payment): bool {
    if ($payment->getRemoteState() === 'voided'
      || in_array($payment->getState()->getId(), ['refunded', 'authorization_voided'], TRUE)) {
      return FALSE;
    }
    return $this->refundOperationManager->canRefund($payment);
  }

  /**
   * {@inheritdoc}
   */
  public function refundPayment(
    PaymentInterface $payment,
    ?Price $amount = NULL,
  ): void {
    if ($amount !== NULL) {
      $this->refundAmount($payment, $amount);
      return;
    }
    $this->refundOperationManager->refund($payment, $this);
  }

  /**
   * {@inheritdoc}
   */
  public function getRefundableItems(PaymentInterface $payment): array {
    return $this->refundOperationManager->getRefundableItems($payment);
  }

  /**
   * {@inheritdoc}
   */
  public function getAllRefundItems(PaymentInterface $payment): array {
    return $this->refundOperationManager->getAllRefundItems($payment);
  }

  /**
   * {@inheritdoc}
   */
  public function refundItems(
    PaymentInterface $payment,
    array $quantities,
    array $amounts = [],
  ): void {
    $this->refundOperationManager->refund($payment, $this, $quantities, $amounts);
  }

  /**
   * {@inheritdoc}
   */
  public function refundAmount(PaymentInterface $payment, Price|string $amount): void {
    $this->refundOperationManager->refundAmount($payment, $this, $amount);
  }

  /**
   * {@inheritdoc}
   */
  public function canCheckRefundStatus(PaymentInterface $payment): bool {
    return $this->refundOperationManager->canCheckStatus($payment);
  }

  /**
   * {@inheritdoc}
   */
  public function checkRefundStatus(
    PaymentInterface $payment,
  ): RefundStatusCheckResult {
    return $this->refundOperationManager->checkStatus($payment, $this);
  }

  /**
   * Returns whether the payment can be reconciled with a read-only status call.
   */
  public function canCheckPaymentStatus(PaymentInterface $payment): bool {
    return $this->paymentStatusCheckManager->canCheckStatus($payment);
  }

  /**
   * Reconciles a payment with NovaPay without submitting a financial command.
   */
  public function checkPaymentStatus(
    PaymentInterface $payment,
  ): PaymentStatusCheckResult {
    return $this->paymentStatusCheckManager->checkStatus($payment, $this);
  }

  /**
   * {@inheritdoc}
   *
   * @return array<string, array<string, mixed>>
   *   Payment operations keyed by operation ID.
   */
  public function buildPaymentOperations(PaymentInterface $payment): array {
    $operations = parent::buildPaymentOperations($payment);
    if (isset($operations['capture'])) {
      $title = $this->t('Confirm hold', [], ['context' => 'NovaPay hold confirmation']);
      $operations['capture']['title'] = $title;
      $operations['capture']['page_title'] = $title;
    }
    if (isset($operations['void'])) {
      $title = $this->t('Refund', [], ['context' => 'NovaPay refund operation']);
      $operations['void']['title'] = $title;
      $operations['void']['page_title'] = $title;
    }
    if (isset($operations['refund'])) {
      $title = $this->t('Refund', [], ['context' => 'NovaPay refund operation']);
      $operations['refund']['title'] = $title;
      $operations['refund']['page_title'] = $title;
    }
    if ($payment->getRemoteState() === 'voided'
      || in_array($payment->getState()->getId(), ['refunded', 'authorization_voided'], TRUE)) {
      unset($operations['void'], $operations['refund'], $operations['capture']);
    }
    $operations['check_payment_status'] = [
      'title' => $this->t('Check NovaPay payment status'),
      'page_title' => $this->t('Check NovaPay payment status'),
      'plugin_form' => 'check-payment-status',
      'access' => $this->canCheckPaymentStatus($payment),
    ];
    $operations['check_refund_status'] = [
      'title' => $this->t('Check refund status'),
      'page_title' => $this->t('Check NovaPay refund status'),
      'plugin_form' => 'check-refund-status',
      'access' => $this->canCheckRefundStatus($payment),
    ];
    return $operations;
  }

  /**
   * {@inheritdoc}
   *
   * Browser returns are never a source of financial payment state.
   */
  public function onReturn(OrderInterface $order, Request $request): void {}

  /**
   * {@inheritdoc}
   */
  public function onNotify(Request $request): Response {
    $gateway = $this->parentEntity;
    $raw_body = $request->getContent();
    try {
      $result = $this->postbackProcessor->process(
        $gateway,
        $this,
        $raw_body,
        (string) $request->headers->get('x-sign', ''),
      );
    }
    catch (\Throwable $exception) {
      $this->logger->logError('postback_processing_failed', [
        'gateway' => (string) $gateway->id(),
        'source' => $exception::class,
      ]);
      return new Response('', Response::HTTP_INTERNAL_SERVER_ERROR);
    }

    $version = $result->getVersion();
    $status = $result->getStatus();
    $context = [
      'gateway' => (string) $gateway->id(),
      'outcome' => $result->getOutcome()->value,
      'version' => $version === NULL ? 'unknown' : $version->value,
      'status' => $status === NULL ? 'unknown' : $status->value,
      'diagnostics' => $result->getDiagnostics(),
    ];
    if ($result->getOutcome() === PostbackOutcome::InvalidSignature) {
      $this->logger->logDetailed(
        $result->isDetailedLoggingEnabled(),
        'postback',
        $context + [
          'payload_bytes' => strlen($raw_body),
          'payload_sha256' => hash('sha256', $raw_body),
        ],
      );
    }
    else {
      $this->logger->logDetailedJson(
        $result->isDetailedLoggingEnabled(),
        'postback',
        $raw_body,
        $context,
      );
    }
    if (in_array(
      $result->getOutcome(),
      [
        PostbackOutcome::InvalidSignature,
        PostbackOutcome::InvalidPayload,
      ],
      TRUE,
    )) {
      $this->logger->logError('postback_rejected', [
        'gateway' => $context['gateway'],
        'outcome' => $context['outcome'],
      ]);
    }

    return new Response('', match ($result->getOutcome()) {
      PostbackOutcome::InvalidSignature => Response::HTTP_FORBIDDEN,
      PostbackOutcome::InvalidPayload => Response::HTTP_BAD_REQUEST,
      PostbackOutcome::Applied,
      PostbackOutcome::Duplicate,
      PostbackOutcome::Ignored,
      PostbackOutcome::UnknownPayment => Response::HTTP_OK,
    });
  }

}
