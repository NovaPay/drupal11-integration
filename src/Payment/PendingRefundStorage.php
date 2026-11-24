<?php

declare(strict_types=1);

namespace Drupal\commerce_novapay\Payment;

use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\commerce_payment\Entity\PaymentInterface;
use Drupal\commerce_payment\Exception\InvalidRequestException;
use Drupal\commerce_price\Price;

/**
 * Encodes and persists durable pending refund intent.
 */
final class PendingRefundStorage {

  use StringTranslationTrait;

  private const int MAX_PENDING_BYTES = 65535;

  private const string INVALID_PENDING_SELECTION = 'The pending NovaPay refund selection is invalid.';

  /**
   * Constructs the pending refund storage.
   */
  public function __construct(
    private readonly RefundSelectionBuilder $selections,
  ) {}

  /**
   * Checks whether durable refund intent can be reconciled.
   */
  public function canCheckStatus(PaymentInterface $payment): bool {
    try {
      return $payment->hasField('novapay_pending_operation')
        && $payment->get('novapay_pending_operation')->getString()
          === PendingOperation::Refund->value
        && $payment->hasField('novapay_pending_refund')
        && !$payment->get('novapay_pending_refund')->isEmpty()
        && trim((string) $payment->getRemoteId()) !== '';
    }
    catch (\Throwable) {
      return FALSE;
    }
  }

  /**
   * Persists a bounded refund intent before the non-idempotent API request.
   *
   * @param \Drupal\commerce_payment\Entity\PaymentInterface $payment
   *   The current payment.
   * @param bool $is_full
   *   Whether NovaPay receives a full refund without operations.
   * @param list<\Drupal\commerce_novapay\Payment\RefundSelection> $items
   *   Pending selections.
   * @param \Drupal\commerce_price\Price $amount
   *   Exact pending amount.
   */
  public function markPending(
    PaymentInterface $payment,
    bool $is_full,
    array $items,
    Price $amount,
  ): void {
    $payload = $this->encodePending($is_full, FALSE, $items);
    if (strlen($payload) > self::MAX_PENDING_BYTES) {
      throw InvalidRequestException::createForPayment(
        $payment,
        (string) $this->t('The refund contains too many order items.'),
      );
    }

    $payment->set('novapay_pending_operation', PendingOperation::Refund->value);
    $payment->set('novapay_pending_amount', $amount->getNumber());
    $payment->set('novapay_pending_refund', $payload);
    $payment->save();
  }

  /**
   * Restores a previously validated pending selection.
   *
   * @return array{bool, bool, list<\Drupal\commerce_novapay\Payment\RefundSelection>}
   *   Full flag, processing-observed flag, and selections.
   */
  public function decodePending(PaymentInterface $payment): array {
    try {
      $payload = json_decode(
        $payment->get('novapay_pending_refund')->getString(),
        TRUE,
        32,
        JSON_THROW_ON_ERROR,
      );
    }
    catch (\JsonException) {
      throw new \UnexpectedValueException(
        self::INVALID_PENDING_SELECTION,
      );
    }
    if (
      !is_array($payload)
      || !is_bool($payload['full'] ?? NULL)
      || !is_bool($payload['processing_seen'] ?? NULL)
      || !is_array($payload['items'] ?? NULL)
      || !array_is_list($payload['items'])
    ) {
      throw new \UnexpectedValueException(
        self::INVALID_PENDING_SELECTION,
      );
    }

    $amount = $payment->getAmount();
    if (!$amount instanceof Price) {
      throw new \UnexpectedValueException(
        'The pending NovaPay refund currency is invalid.',
      );
    }
    $mode = $payload['mode'] ?? 'items';
    if (!in_array($mode, ['items', 'amount'], TRUE)) {
      throw new \UnexpectedValueException(self::INVALID_PENDING_SELECTION);
    }
    $items = [];
    foreach ($payload['items'] as $item) {
      if (
        !is_array($item)
        || ($mode === 'items'
          ? !is_int($item['order_item_id'] ?? NULL)
          : !array_key_exists('order_item_id', $item)
            || $item['order_item_id'] !== NULL
            || ($item['quantity'] ?? NULL) !== '0'
            || count($payload['items']) !== 1)
        || !is_string($item['quantity'] ?? NULL)
        || !is_string($item['amount'] ?? NULL)
      ) {
        throw new \UnexpectedValueException(
          self::INVALID_PENDING_SELECTION,
        );
      }
      $items[] = new RefundSelection(
        $item['order_item_id'],
        $this->selections->normalizeQuantity($item['quantity'], $payment, TRUE),
        $mode === 'amount'
          ? RefundAmount::parse(new Price($item['amount'], $amount->getCurrencyCode()))
          : new Price($item['amount'], $amount->getCurrencyCode()),
      );
    }
    if ($items === []) {
      throw new \UnexpectedValueException(
        'The pending NovaPay refund selection is empty.',
      );
    }

    return [$payload['full'], $payload['processing_seen'], $items];
  }

  /**
   * Encodes bounded pending refund metadata.
   *
   * @param bool $is_full
   *   Whether the API request omitted operations for a full refund.
   * @param bool $processing_seen
   *   Whether processing_void was observed after submission.
   * @param list<\Drupal\commerce_novapay\Payment\RefundSelection> $items
   *   Validated pending item selections.
   */
  public function encodePending(
    bool $is_full,
    bool $processing_seen,
    array $items,
  ): string {
    return json_encode([
      'mode' => $items[0]->getOrderItemId() === NULL ? 'amount' : 'items',
      'full' => $is_full,
      'processing_seen' => $processing_seen,
      'items' => array_map(
        static fn (RefundSelection $item): array => $item->toArray(),
        $items,
      ),
    ], JSON_THROW_ON_ERROR);
  }

  /**
   * Clears all durable pending-refund metadata.
   */
  public function clearPending(PaymentInterface $payment): void {
    $payment->set('novapay_pending_operation', NULL);
    $payment->set('novapay_pending_amount', NULL);
    $payment->set('novapay_pending_refund', NULL);
  }

}
