<?php

declare(strict_types=1);

namespace Drupal\commerce_novapay\Payment;

use Drupal\commerce_novapay\Api\Dto\Response\SessionStatusResponse;
use Drupal\commerce_novapay\Postback\Dto\NormalizedPostbackEvent;
use Drupal\commerce_novapay\Postback\NovaPayStatus;
use Drupal\commerce_payment\Entity\PaymentInterface;
use Drupal\commerce_price\Price;

/**
 * Resolves ambiguous session voids using exact operation refund evidence.
 */
final class RefundStatusEvidence {

  /**
   * Returns a safe confirmation event, or NULL for insufficient evidence.
   */
  public static function resolve(PaymentInterface $payment, SessionStatusResponse $response): ?NormalizedPostbackEvent {
    if ($response->getSessionId() !== $payment->getRemoteId()
      || !in_array($response->getStatus(), [NovaPayStatus::Paid, NovaPayStatus::HoldConfirmed, NovaPayStatus::Voided], TRUE)) {
      return NULL;
    }
    $operation_id = $payment->get('novapay_operation_id')->getString();
    $reported = $response->getRefundedAmount($operation_id);
    $amount = $payment->getAmount();
    $previous = $payment->getRefundedAmount();
    if ($operation_id === '' || $reported === NULL || !$amount instanceof Price || !$previous instanceof Price) {
      return NULL;
    }
    $refunded = new Price($reported, $amount->getCurrencyCode());
    $status = NovaPayStatus::Paid;
    if ($refunded->equals($amount)) {
      $status = NovaPayStatus::Voided;
    }
    elseif (!$refunded->lessThan($amount) || $refunded->lessThan($previous)) {
      return NULL;
    }
    elseif ($payment->get('novapay_pending_operation')->getString() === PendingOperation::Refund->value) {
      try {
        $pending = RefundAmount::parse($payment->get('novapay_pending_amount')->getString());
      }
      catch (\RuntimeException) {
        return NULL;
      }
      if (!$refunded->equals($previous->add($pending))) {
        return NULL;
      }
    }
    elseif (!$refunded->equals($previous)) {
      return NULL;
    }
    return NormalizedPostbackEvent::fromValues($response->getSessionId(), $status->value, [], [$reported]);
  }

}
