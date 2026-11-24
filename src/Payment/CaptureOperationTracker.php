<?php

declare(strict_types=1);

namespace Drupal\commerce_novapay\Payment;

use Drupal\commerce_novapay\Postback\NovaPayStatus;
use Drupal\commerce_payment\Entity\PaymentInterface;
use Drupal\commerce_price\Price;

/**
 * Updates the existing operation ID after authoritative capture confirmation.
 */
final class CaptureOperationTracker {

  /**
   * Identifies pending partial captures that need a fresh operation ID.
   */
  public static function requiresLookup(PaymentInterface $payment, NovaPayStatus $status, ?string $operation_id): bool {
    if (!in_array($status, [NovaPayStatus::Paid, NovaPayStatus::HoldConfirmed], TRUE)
      || !$payment->hasField('novapay_pending_operation')
      || $payment->getState()->getId() !== 'authorization'
      || $payment->get('novapay_pending_operation')->getString() !== PendingOperation::Capture->value) {
      return FALSE;
    }
    $authorized = $payment->getAmount();
    $number = $payment->get('novapay_pending_amount')->getString();
    if (!$authorized instanceof Price || $number === '') {
      throw new \UnexpectedValueException('The pending NovaPay capture amount is invalid.');
    }
    return (new Price($number, $authorized->getCurrencyCode()))->lessThan($authorized)
      && ($operation_id === NULL || $operation_id === trim($payment->get('novapay_operation_id')->getString()));
  }

  /**
   * Applies evidence within the caller's session lock and transaction.
   */
  public static function apply(PaymentInterface $payment, NovaPayStatus $status, ?string $operation_id): bool {
    if (
      !in_array($status, [NovaPayStatus::Paid, NovaPayStatus::HoldConfirmed], TRUE)
      || !$payment->hasField('novapay_pending_operation')
      || $payment->getState()->getId() !== 'authorization'
      || $payment->get('novapay_pending_operation')->getString() !== PendingOperation::Capture->value
    ) {
      return FALSE;
    }

    $current_id = trim($payment->get('novapay_operation_id')->getString());
    $authorized = $payment->getAmount();
    $number = $payment->get('novapay_pending_amount')->getString();
    if (!$authorized instanceof Price || $number === '') {
      throw new \UnexpectedValueException('The pending NovaPay capture amount is invalid.');
    }
    $partial = (new Price($number, $authorized->getCurrencyCode()))->lessThan($authorized);
    if ($partial && ($operation_id === NULL || $operation_id === $current_id)) {
      throw new \UnexpectedValueException('The confirmed partial capture operation ID is unavailable or ambiguous. Check NovaPay payment status before refunding.');
    }
    if ($operation_id === NULL || $operation_id === $current_id) {
      return FALSE;
    }
    $payment->set('novapay_operation_id', $operation_id);
    return TRUE;
  }

}
