<?php

declare(strict_types=1);

namespace Drupal\commerce_novapay\Payment;

use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\commerce_order\Adjustment;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_payment\Entity\PaymentInterface;
use Drupal\commerce_price\Price;

/**
 * Reduces order totals by confirmed partial NovaPay refunds.
 */
final class RefundOrderAdjustment {

  use StringTranslationTrait;

  /**
   * Applies one cumulative adjustment per payment without saving the order.
   */
  public function apply(OrderInterface $order, PaymentInterface $payment): void {
    if ($payment->getPaymentGateway()?->getPluginId() !== 'novapay'
      || $payment->getState()->getId() !== 'partially_refunded') {
      return;
    }
    if ((string) $payment->getOrderId() !== (string) $order->id()) {
      throw new \UnexpectedValueException('The refund payment belongs to another order.');
    }
    $refunded = $payment->getRefundedAmount();
    $captured = $payment->getAmount();
    if (!$refunded instanceof Price || !$captured instanceof Price
      || !$refunded->isPositive() || !$refunded->lessThan($captured)) {
      throw new \UnexpectedValueException('The confirmed partial refund amount is invalid.');
    }
    $source = 'novapay_partial_refund:' . $payment->id();
    $amount = $refunded->multiply('-1');
    foreach ($order->getAdjustments() as $adjustment) {
      if ($adjustment->getType() === 'novapay_partial_refund'
        && $adjustment->getSourceId() === $source) {
        $order->removeAdjustment($adjustment);
      }
    }
    $order->addAdjustment(new Adjustment([
      'type' => 'novapay_partial_refund',
      'label' => 'NovaPay partial refund adjustment',
      'amount' => $amount,
      'source_id' => $source,
      'locked' => TRUE,
    ]));
  }

}
