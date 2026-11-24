<?php

declare(strict_types=1);

namespace Drupal\commerce_novapay\Payment;

use Drupal\Core\Entity\Display\EntityFormDisplayInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_order\Entity\OrderItemInterface;
use Drupal\commerce_payment\Entity\PaymentInterface;
use Drupal\commerce_payment\Exception\InvalidRequestException;
use Drupal\commerce_price\Calculator;
use Drupal\commerce_price\Price;

/**
 * Calculates refundable items and validates exact refund selections.
 */
final class RefundSelectionBuilder {

  use StringTranslationTrait;

  /**
   * Constructs the refund selection builder.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entity_type_manager,
    private readonly RefundLedgerRepositoryInterface $ledger,
    private readonly RefundPaymentStorage $payments,
  ) {}

  /**
   * Gets refundable item quantities after subtracting confirmed ledger entries.
   *
   * @return list<\Drupal\commerce_novapay\Payment\RefundableItem>
   *   Refundable order items.
   */
  public function getRefundableItems(PaymentInterface $payment): array {
    return $this->buildRefundableItems($payment, TRUE);
  }

  /**
   * Gets all order items with confirmed refund details for display.
   *
   * @return list<\Drupal\commerce_novapay\Payment\RefundableItem>
   *   Order items including fully refunded items.
   */
  public function getAllRefundItems(PaymentInterface $payment): array {
    return $this->buildRefundableItems($payment, FALSE);
  }

  /**
   * Builds refundable or all items for a payment.
   *
   * @param \Drupal\commerce_payment\Entity\PaymentInterface $payment
   *   The NovaPay payment.
   * @param bool $only_available
   *   Whether to omit items with zero available quantity.
   *
   * @return list<\Drupal\commerce_novapay\Payment\RefundableItem>
   *   Built item list.
   */
  private function buildRefundableItems(PaymentInterface $payment, bool $only_available): array {
    $payment_id = $this->payments->requirePaymentId($payment);
    $order = $payment->getOrder();
    if (!$order instanceof OrderInterface) {
      throw InvalidRequestException::createForPayment(
        $payment,
        (string) $this->t('The payment order is unavailable.'),
      );
    }

    $currency = $payment->getAmount()?->getCurrencyCode();
    $refunded = $this->ledger->getRefundedQuantities($payment_id);
    $refunded_amounts = $this->ledger->getRefundedAmounts($payment_id);
    $items = [];
    foreach ($order->getItems() as $order_item) {
      $order_item_id = $this->payments->normalizeEntityId($order_item->id());
      $unit_price = $order_item->getAdjustedUnitPrice();
      if (
        $order_item_id === NULL
        || !$unit_price instanceof Price
        || $unit_price->getCurrencyCode() !== $currency
        || !$unit_price->isPositive()
      ) {
        continue;
      }

      $ordered_quantity = $this->normalizeQuantity(
        $order_item->getQuantity(),
        $payment,
      );
      $refunded_quantity = $refunded[$order_item_id] ?? '0';
      $refunded_amount_number = $refunded_amounts[$order_item_id] ?? '0';
      $refunded_amount = new Price($refunded_amount_number, $currency);
      $available_quantity = Calculator::subtract(
        $ordered_quantity,
        $refunded_quantity,
      );
      if ($only_available && Calculator::compare($available_quantity, '0') <= 0) {
        continue;
      }

      $items[] = new RefundableItem(
        $order_item_id,
        $order_item->getTitle(),
        $ordered_quantity,
        $refunded_quantity,
        Calculator::compare($available_quantity, '0') > 0 ? $available_quantity : '0',
        $this->getQuantityStep($order_item),
        $unit_price,
        $refunded_amount,
      );
    }

    return $items;
  }

  /**
   * Builds a validated selection and detects empty/all-item full intent.
   *
   * @param \Drupal\commerce_payment\Entity\PaymentInterface $payment
   *   The current payment.
   * @param array<int, string> $quantities
   *   Submitted quantities keyed by order item ID.
   *
   * @return array{list<\Drupal\commerce_novapay\Payment\RefundSelection>, bool}
   *   Validated selections.
   */
  public function buildPartialSelection(
    PaymentInterface $payment,
    array $quantities,
  ): array {
    $available_items = [];
    foreach ($this->getRefundableItems($payment) as $item) {
      $available_items[$item->getOrderItemId()] = $item;
    }

    $selections = [];
    foreach ($quantities as $order_item_id => $quantity) {
      if (!isset($available_items[$order_item_id])) {
        throw InvalidRequestException::createForPayment(
          $payment,
          (string) $this->t('The refund contains an unavailable order item.'),
        );
      }
      $quantity = $this->normalizeQuantity($quantity, $payment, TRUE);
      if ($quantity === '0') {
        continue;
      }
      $item = $available_items[$order_item_id];
      if (
        Calculator::compare($quantity, $item->getAvailableQuantity()) > 0
      ) {
        throw InvalidRequestException::createForPayment(
          $payment,
          (string) $this->t('The refund quantity exceeds the paid quantity.'),
        );
      }
      if (!$item->isQuantityMultiple($quantity)) {
        throw InvalidRequestException::createForPayment(
          $payment,
          (string) $this->t('The refund quantity does not match the order item quantity step.'),
        );
      }
      $selections[] = new RefundSelection(
        $order_item_id,
        $quantity,
        $item->getUnitPrice()->multiply($quantity),
      );
    }
    $all_items = $selections === [];
    if ($selections !== [] && count($selections) === count($available_items)) {
      $all_items = TRUE;
      foreach ($selections as $selection) {
        $order_item_id = $selection->getOrderItemId();
        if ($order_item_id === NULL || !isset($available_items[$order_item_id])) {
          $all_items = FALSE;
          break;
        }
        $available = $available_items[$order_item_id];
        if (Calculator::compare($selection->getQuantity(), $available->getAvailableQuantity()) !== 0) {
          $all_items = FALSE;
          break;
        }
      }
    }

    return [$selections, $all_items];
  }

  /**
   * Validates explicit line amounts without treating all items as full intent.
   *
   * @param \Drupal\commerce_payment\Entity\PaymentInterface $payment
   *   The current payment.
   * @param array<int, string> $quantities
   *   Selected quantities keyed by item ID.
   * @param array<int, string> $amounts
   *   Explicit line amounts keyed by item ID.
   *
   * @return list<\Drupal\commerce_novapay\Payment\RefundSelection>
   *   Validated item amounts and quantities.
   */
  public function buildAmountSelection(PaymentInterface $payment, array $quantities, array $amounts): array {
    [$selected] = $this->buildPartialSelection($payment, $quantities);
    $items = [];
    foreach ($selected as $selection) {
      $items[$selection->getOrderItemId()] = $selection;
    }
    $available = [];
    foreach ($this->getRefundableItems($payment) as $item) {
      $available[$item->getOrderItemId()] = TRUE;
    }
    foreach ($amounts as $id => $value) {
      if (!isset($available[$id])
        || preg_match('/^(?:0|[1-9][0-9]*)(?:\.[0-9]{1,2})?$/D', $value) !== 1) {
        throw InvalidRequestException::createForPayment($payment, (string) $this->t('Invalid refund item amount.'));
      }
      if (Calculator::compare($value, '0') === 0) {
        if (isset($items[$id])) {
          throw InvalidRequestException::createForPayment($payment, (string) $this->t('A selected quantity requires a positive refund amount.'));
        }
        continue;
      }
      $items[$id] = new RefundSelection($id, isset($items[$id]) ? $items[$id]->getQuantity() : '0', new Price($value, $payment->getBalance()->getCurrencyCode()));
    }
    $items = array_values($items);
    if ($items === []) {
      return [new RefundSelection(NULL, '0', $this->validateAmount($payment->getBalance(), $payment))];
    }
    $this->getSelectionTotal($items, $payment);
    return $items;
  }

  /**
   * Gets a selection total and checks it against the payment balance.
   *
   * @param list<\Drupal\commerce_novapay\Payment\RefundSelection> $items
   *   Refund selections.
   * @param \Drupal\commerce_payment\Entity\PaymentInterface $payment
   *   The current payment.
   */
  public function getSelectionTotal(
    array $items,
    PaymentInterface $payment,
  ): Price {
    $balance = $payment->getBalance();
    if (!$balance instanceof Price) {
      throw InvalidRequestException::createForPayment(
        $payment,
        (string) $this->t('The refundable payment balance is unavailable.'),
      );
    }
    $total = new Price('0', $balance->getCurrencyCode());
    foreach ($items as $item) {
      $total = $total->add($item->getAmount());
    }
    if (!$total->isPositive() || $total->greaterThan($balance)) {
      throw InvalidRequestException::createForPayment(
        $payment,
        (string) $this->t('The refund amount exceeds the paid balance.'),
      );
    }

    return $total;
  }

  /**
   * Provides a translated error at the monetary operation boundary.
   */
  public function validateAmount(mixed $value, PaymentInterface $payment): Price {
    try {
      return RefundAmount::parse($value);
    }
    catch (InvalidRequestException $exception) {
      throw InvalidRequestException::createForPayment(
        $payment,
        (string) $this->t('Enter a positive UAH refund amount with at most two decimal places.'),
        previous: $exception,
      );
    }
  }

  /**
   * Validates an exact non-negative quantity string.
   */
  public function normalizeQuantity(
    mixed $quantity,
    PaymentInterface $payment,
    bool $allow_zero = FALSE,
  ): string {
    if (!is_string($quantity)) {
      throw InvalidRequestException::createForPayment(
        $payment,
        (string) $this->t('Refund quantities must be decimal strings.'),
      );
    }
    $quantity = trim($quantity);
    if ($allow_zero && $quantity === '') {
      return '0';
    }
    if (preg_match('/^(?:0|[1-9][0-9]*)(?:\.[0-9]{1,6})?$/D', $quantity) !== 1) {
      throw InvalidRequestException::createForPayment(
        $payment,
        (string) $this->t('A refund quantity has an invalid format.'),
      );
    }
    $quantity = Calculator::trim($quantity);
    if (!$allow_zero && Calculator::compare($quantity, '0') <= 0) {
      throw InvalidRequestException::createForPayment(
        $payment,
        (string) $this->t('A refund quantity must be greater than zero.'),
      );
    }

    return $quantity;
  }

  /**
   * Gets the quantity widget step configured for an order item bundle.
   *
   * Commerce persists a disabled "Allow decimal quantities" setting as a
   * step of 1, so the widget step represents both administrator settings.
   */
  private function getQuantityStep(OrderItemInterface $order_item): string {
    $display_id = implode('.', [
      'commerce_order_item',
      $order_item->bundle(),
      'default',
    ]);
    $display = $this->entity_type_manager
      ->getStorage('entity_form_display')
      ->load($display_id);
    $component = $display instanceof EntityFormDisplayInterface
      ? $display->getComponent('quantity')
      : NULL;
    $step = is_array($component)
      && ($component['type'] ?? NULL) === 'commerce_quantity'
      && is_array($component['settings'] ?? NULL)
      ? $component['settings']['step'] ?? NULL
      : NULL;
    if (
      !is_string($step)
      || preg_match('/^(?:0\.[0-9]{1,6}|[1-9][0-9]*)$/D', $step) !== 1
      || Calculator::compare($step, '0') <= 0
    ) {
      return '1';
    }

    return Calculator::trim($step);
  }

}
