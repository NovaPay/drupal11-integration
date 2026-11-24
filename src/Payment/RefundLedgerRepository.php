<?php

declare(strict_types=1);

namespace Drupal\commerce_novapay\Payment;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Drupal\commerce_price\Calculator;
use Drupal\commerce_price\Price;

/**
 * Database-backed ledger containing confirmed refunds only.
 */
final class RefundLedgerRepository implements RefundLedgerRepositoryInterface {

  public function __construct(
    private readonly Connection $database,
    private readonly TimeInterface $time,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function getRefundedQuantities(int $payment_id): array {
    $result = $this->database
      ->select('commerce_novapay_refund_ledger', 'refund')
      ->fields('refund', ['order_item_id', 'quantity'])
      ->condition('payment_id', $payment_id)
      ->isNotNull('order_item_id')
      ->execute();

    $quantities = [];
    foreach ($result as $row) {
      $order_item_id = (int) $row->order_item_id;
      $quantities[$order_item_id] = Calculator::add(
        $quantities[$order_item_id] ?? '0',
        (string) $row->quantity,
      );
    }

    return $quantities;
  }

  /**
   * {@inheritdoc}
   */
  public function getRefundedAmounts(int $payment_id): array {
    $result = $this->database
      ->select('commerce_novapay_refund_ledger', 'refund')
      ->fields('refund', ['order_item_id', 'amount'])
      ->condition('payment_id', $payment_id)
      ->isNotNull('order_item_id')
      ->execute();

    $amounts = [];
    foreach ($result as $row) {
      $order_item_id = (int) $row->order_item_id;
      $amounts[$order_item_id] = Calculator::add(
        $amounts[$order_item_id] ?? '0',
        (string) $row->amount,
      );
    }

    return $amounts;
  }

  /**
   * {@inheritdoc}
   */
  public function getRefundedAmountForOrderItem(int $order_item_id): ?Price {
    $result = $this->database
      ->select('commerce_novapay_refund_ledger', 'refund')
      ->fields('refund', ['amount', 'currency_code'])
      ->condition('order_item_id', $order_item_id)
      ->execute();

    $total = '0';
    $currency = NULL;
    foreach ($result as $row) {
      $total = Calculator::add($total, (string) $row->amount);
      $currency = (string) $row->currency_code;
    }
    if ($currency === NULL || Calculator::compare($total, '0') <= 0) {
      return NULL;
    }
    return new Price($total, $currency);
  }

  /**
   * {@inheritdoc}
   */
  public function recordConfirmed(
    int $payment_id,
    string $event_key,
    array $items,
  ): void {
    $transaction = $this->database->startTransaction();
    try {
      foreach ($items as $item) {
        $this->database->insert('commerce_novapay_refund_ledger')
          ->fields([
            'payment_id' => $payment_id,
            'order_item_id' => $item->getOrderItemId(),
            'selection_key' => $item->getOrderItemId() === NULL
              ? 'amount' : 'item:' . $item->getOrderItemId(),
            'quantity' => $item->getQuantity(),
            'amount' => $item->getAmount()->getNumber(),
            'currency_code' => $item->getAmount()->getCurrencyCode(),
            'event_key' => $event_key,
            'confirmed' => $this->time->getRequestTime(),
          ])
          ->execute();
      }
    }
    catch (\Throwable $exception) {
      $transaction->rollBack();
      throw $exception;
    }
  }

}
