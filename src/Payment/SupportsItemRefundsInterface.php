<?php

declare(strict_types=1);

namespace Drupal\commerce_novapay\Payment;

use Drupal\commerce_payment\Entity\PaymentInterface;
use Drupal\commerce_price\Price;

/**
 * Exposes NovaPay item-level refunds to the custom Commerce plugin form.
 */
interface SupportsItemRefundsInterface {

  /**
   * Gets the current item-level refund availability.
   *
   * @param \Drupal\commerce_payment\Entity\PaymentInterface $payment
   *   The NovaPay payment.
   *
   * @return list<\Drupal\commerce_novapay\Payment\RefundableItem>
   *   Current refundable items.
   */
  public function getRefundableItems(PaymentInterface $payment): array;

  /**
   * Gets all order items with confirmed refund details for display.
   *
   * @param \Drupal\commerce_payment\Entity\PaymentInterface $payment
   *   The NovaPay payment.
   *
   * @return list<\Drupal\commerce_novapay\Payment\RefundableItem>
   *   Order items including fully refunded items.
   */
  public function getAllRefundItems(PaymentInterface $payment): array;

  /**
   * Submits selected item quantities, or an empty selection for a full refund.
   *
   * @param \Drupal\commerce_payment\Entity\PaymentInterface $payment
   *   The NovaPay payment.
   * @param array<int, string> $quantities
   *   Selected quantities keyed by order item ID.
   * @param array<int, string> $amounts
   *   Optional explicit amounts keyed by order item ID.
   */
  public function refundItems(
    PaymentInterface $payment,
    array $quantities,
    array $amounts = [],
  ): void;

  /**
   * Submits a monetary compensation without changing item quantities.
   */
  public function refundAmount(PaymentInterface $payment, Price|string $amount): void;

  /**
   * Checks whether the payment has a pending refund status operation.
   */
  public function canCheckRefundStatus(PaymentInterface $payment): bool;

  /**
   * Checks and safely reconciles the pending refund at NovaPay.
   */
  public function checkRefundStatus(
    PaymentInterface $payment,
  ): RefundStatusCheckResult;

}
