<?php

declare(strict_types=1);

namespace Drupal\commerce_novapay\Payment;

use Drupal\Core\Database\Connection;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\commerce_payment\Exception\PaymentGatewayException;
use Drupal\commerce_novapay\Postback\Dto\NormalizedPostbackEvent;
use Drupal\commerce_novapay\Postback\NovaPayStatus;
use Drupal\commerce_payment\Entity\PaymentInterface;
use Drupal\commerce_price\Price;

/**
 * Applies authoritative refund evidence atomically to ledger and payment.
 */
final class RefundConfirmationManager {

  use StringTranslationTrait;

  /**
   * Constructs the refund confirmation manager.
   */
  public function __construct(
    private readonly PendingRefundStorage $pending,
    private readonly RefundSelectionBuilder $selections,
    private readonly RefundPaymentStorage $payments,
    private readonly RefundLedgerRepositoryInterface $ledger,
    private readonly Connection $database,
    private readonly PaymentOrderLockInterface $orderLock,
  ) {}

  /**
   * Commits manually reconciled refund evidence and its final transition.
   */
  public function reconcile(
    PaymentInterface $current,
    NormalizedPostbackEvent $event,
    string $event_key,
    bool $is_full,
    NovaPayStatus $status,
  ): RefundStatusCheckResult {
    $order_lock = $this->orderLock->acquire($current);
    $transaction = NULL;
    try {
      $transaction = $this->database->startTransaction();
      $this->confirm($current, $event, $event_key);
      if ($this->pending->canCheckStatus($current)) {
        $transaction->commitOrRelease();
        return RefundStatusCheckResult::Pending;
      }

      if (
        ($is_full || $event->getStatus() === NovaPayStatus::Voided)
        && $current->getState()->getId() !== 'refunded'
        && $current->getState()->isTransitionAllowed('refund')
      ) {
        $current->getState()->applyTransitionById('refund');
        $current->setRemoteState($status->value);
        $current->save();
      }
      $transaction->commitOrRelease();
    }
    catch (\Throwable $exception) {
      $transaction?->rollBack();
      throw PaymentGatewayException::createForPayment(
        $current,
        (string) $this->t('The confirmed NovaPay refund could not be saved atomically.'),
        previous: $exception,
      );
    }
    finally {
      $this->orderLock->release($order_lock);
    }
    return RefundStatusCheckResult::Confirmed;
  }

  /**
   * Requires documented status/amount evidence before confirming a refund.
   *
   * @return array{bool, \Drupal\commerce_novapay\Postback\NovaPayStatus}
   *   Whether confirmation is permitted and the normalized confirmation status.
   */
  public function getStatusConfirmation(
    PaymentInterface $payment,
    bool $is_full,
    NovaPayStatus $status,
    ?string $refunded_amount,
  ): array {
    $confirmation_status = $status;
    if ($status === NovaPayStatus::Voided) {
      return [TRUE, $status];
    }
    $may_confirm = FALSE;
    if ($is_full && $refunded_amount !== NULL) {
      $payment_amount = $payment->getAmount();
      if ($payment_amount instanceof Price) {
        $reported = new Price(
          $refunded_amount,
          $payment_amount->getCurrencyCode(),
        );
        $may_confirm = $status === NovaPayStatus::Paid
          && $reported->greaterThanOrEqual($payment_amount);
        if ($may_confirm) {
          $confirmation_status = NovaPayStatus::Voided;
        }
      }
    }
    elseif (!$is_full) {
      $may_confirm = $status === NovaPayStatus::Paid
        && $refunded_amount !== NULL;
    }
    return [$may_confirm, $confirmation_status];
  }

  /**
   * Applies signed or manually reconciled refund evidence to pending intent.
   */
  public function confirm(
    PaymentInterface $payment,
    NormalizedPostbackEvent $event,
    string $event_key,
  ): void {
    if (
      !$payment->hasField('novapay_pending_operation')
      || $payment->get('novapay_pending_operation')->getString()
        !== PendingOperation::Refund->value
    ) {
      return;
    }

    [$is_full, $processing_seen, $items] = $this->pending->decodePending($payment);
    // Session-level void is authoritative even if the local intent was partial.
    // Its unspecified remainder has no confirmed allocation to order items.
    if (!$is_full && $event->getStatus() === NovaPayStatus::Voided) {
      $balance = $payment->getBalance();
      if (!$balance instanceof Price || !$balance->isPositive()) {
        throw new \UnexpectedValueException('The refundable payment balance is unavailable.');
      }
      $items = [new RefundSelection(NULL, '0', $balance)];
      $is_full = TRUE;
    }
    if (
      !$is_full
      && !$processing_seen
      && $event->getStatus() === NovaPayStatus::ProcessingVoid
    ) {
      $payment->set(
        'novapay_pending_refund',
        $this->pending->encodePending($is_full, TRUE, $items),
      );
      $payment->save();
      return;
    }
    $pending_amount = $this->selections->getSelectionTotal($items, $payment);
    $payment_amount = $payment->getAmount();
    $refunded_amount = $payment->getRefundedAmount();
    if (!$payment_amount instanceof Price || !$refunded_amount instanceof Price) {
      throw new \UnexpectedValueException(
        'The pending NovaPay refund amount is invalid.',
      );
    }
    $new_refunded_amount = $refunded_amount->add($pending_amount);
    $is_final = $event->getStatus() === NovaPayStatus::Voided;
    if ($is_full && !$is_final) {
      return;
    }
    if (!$is_final && !$this->confirmsPartialAmount(
      $event,
      $processing_seen,
      $pending_amount,
      $new_refunded_amount,
      $payment_amount,
    )) {
      return;
    }

    $this->saveConfirmation(
      $payment,
      $event,
      $event_key,
      $items,
      $is_final,
      $payment_amount,
      $new_refunded_amount,
    );
  }

  /**
   * Commits ledger, monetary state and pending intent in one transaction.
   *
   * @param \Drupal\commerce_payment\Entity\PaymentInterface $payment
   *   The locked payment.
   * @param \Drupal\commerce_novapay\Postback\Dto\NormalizedPostbackEvent $event
   *   The verified confirmation.
   * @param string $event_key
   *   The confirmation journal key.
   * @param list<\Drupal\commerce_novapay\Payment\RefundSelection> $items
   *   The validated refund selection.
   * @param bool $is_final
   *   Whether the session is fully voided.
   * @param \Drupal\commerce_price\Price $payment_amount
   *   The original payment amount.
   * @param \Drupal\commerce_price\Price $new_refunded_amount
   *   The cumulative refunded amount.
   */
  private function saveConfirmation(
    PaymentInterface $payment,
    NormalizedPostbackEvent $event,
    string $event_key,
    array $items,
    bool $is_final,
    Price $payment_amount,
    Price $new_refunded_amount,
  ): void {
    $payment_id = $this->payments->requirePaymentId($payment);
    $transaction = $this->database->startTransaction();
    try {
      $this->ledger->recordConfirmed($payment_id, $event_key, $items);
      if ($is_final) {
        $payment->setRefundedAmount($payment_amount);
        $payment->setRemoteState($event->getStatus()->value);
        if ($payment->getState()->isTransitionAllowed('refund')) {
          $payment->getState()->applyTransitionById('refund');
        }
      }
      else {
        $payment->setRefundedAmount($new_refunded_amount);
        $payment->setRemoteState($event->getStatus()->value);
        if ($payment->getState()->getId() === 'completed') {
          $payment->getState()->applyTransitionById('partially_refund');
        }
      }
      $this->pending->clearPending($payment);
      $payment->save();
      $this->payments->updateOrderTotals($payment, !$is_final);
    }
    catch (\Throwable $exception) {
      $transaction->rollBack();
      throw $exception;
    }
  }

  /**
   * Checks explicit postback refund evidence for a non-final refund.
   *
   * @param \Drupal\commerce_novapay\Postback\Dto\NormalizedPostbackEvent $event
   *   Verified normalized postback event.
   * @param bool $processing_seen
   *   Whether processing_void was observed for this pending intent.
   * @param \Drupal\commerce_price\Price $pending_amount
   *   Exact amount submitted in the current refund request.
   * @param \Drupal\commerce_price\Price $new_refunded_amount
   *   Cumulative confirmed amount after the current refund.
   * @param \Drupal\commerce_price\Price $payment_amount
   *   Original completed payment amount.
   */
  private function confirmsPartialAmount(
    NormalizedPostbackEvent $event,
    bool $processing_seen,
    Price $pending_amount,
    Price $new_refunded_amount,
    Price $payment_amount,
  ): bool {
    if (!$new_refunded_amount->lessThan($payment_amount)) {
      return FALSE;
    }
    if ($event->getStatus() !== NovaPayStatus::Paid) {
      return FALSE;
    }
    $reported = $event->getRefundedAmount();
    if ($reported === NULL) {
      return FALSE;
    }
    $reported_amount = new Price(
      $reported,
      $payment_amount->getCurrencyCode(),
    );

    return $reported_amount->greaterThanOrEqual($pending_amount)
      && $reported_amount->greaterThanOrEqual($new_refunded_amount);
  }

}
