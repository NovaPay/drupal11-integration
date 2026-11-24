<?php

declare(strict_types=1);

namespace Drupal\commerce_novapay\Payment;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_order\OrderStorageInterface;
use Drupal\commerce_payment\Entity\PaymentInterface;
use Drupal\commerce_payment\Exception\InvalidRequestException;
use Drupal\commerce_payment\Exception\PaymentGatewayException;
use Drupal\commerce_payment\PaymentStorageInterface;
use Drupal\commerce_payment\PaymentOrderUpdaterInterface;

/**
 * Loads current refund entities and updates locked order totals.
 */
final class RefundPaymentStorage {

  use StringTranslationTrait;

  /**
   * Constructs the refund payment storage.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entity_type_manager,
    private readonly PaymentOrderUpdaterInterface $order_updater,
    private readonly RefundOrderAdjustment $refundAdjustment,
  ) {}

  /**
   * Loads the persisted payment after discarding the entity cache.
   */
  public function loadCurrentPayment(
    int $payment_id,
    PaymentInterface $payment,
  ): PaymentInterface {
    $storage = $this->entity_type_manager->getStorage('commerce_payment');
    if (!$storage instanceof PaymentStorageInterface) {
      throw PaymentGatewayException::createForPayment(
        $payment,
        (string) $this->t('Commerce payment storage is unavailable.'),
      );
    }
    $storage->resetCache([$payment_id]);
    $current = $storage->load($payment_id);
    if (
      !$current instanceof PaymentInterface
      || $current->getPaymentGatewayId() !== $payment->getPaymentGatewayId()
      || $current->bundle() !== 'novapay_payment'
    ) {
      throw InvalidRequestException::createForPayment(
        $payment,
        (string) $this->t('The NovaPay payment could not be reloaded safely.'),
      );
    }

    return $current;
  }

  /**
   * Gets the required positive numeric payment identifier.
   */
  public function requirePaymentId(PaymentInterface $payment): int {
    $payment_id = $this->normalizeEntityId($payment->id());
    if ($payment_id === NULL) {
      throw InvalidRequestException::createForPayment(
        $payment,
        (string) $this->t('The payment must be saved before it can be refunded.'),
      );
    }

    return $payment_id;
  }

  /**
   * Converts a positive SQL entity identifier to an integer.
   */
  public function normalizeEntityId(mixed $entity_id): ?int {
    if (is_int($entity_id) && $entity_id > 0) {
      return $entity_id;
    }
    if (
      is_string($entity_id)
      && preg_match('/^[1-9][0-9]*$/D', $entity_id) === 1
      && (
        strlen($entity_id) < 10
        || strlen($entity_id) === 10
        && strcmp($entity_id, '4294967295') <= 0
      )
    ) {
      return (int) $entity_id;
    }

    return NULL;
  }

  /**
   * Updates a fresh locked order without running session-dependent processors.
   */
  public function updateOrderTotals(PaymentInterface $payment, bool $partial_refund = FALSE): void {
    $storage = $this->entity_type_manager->getStorage('commerce_order');
    $order_id = (int) $payment->getOrderId();
    if (!$storage instanceof OrderStorageInterface || $order_id <= 0) {
      throw new \UnexpectedValueException('The refund payment order is unavailable.');
    }
    try {
      $order = $storage->loadForUpdate($order_id);
      if (!$order instanceof OrderInterface) {
        throw new \UnexpectedValueException('The refund payment order is unavailable.');
      }
      if ($partial_refund) {
        $this->refundAdjustment->apply($order, $payment);
      }
      $this->order_updater->updateOrder($order);
      $order->setRefreshState(OrderInterface::REFRESH_SKIP);
      $order->save();
      // Subsequent status mapping must use the current version of this order.
      $payment->set('order_id', $order);
    }
    finally {
      $storage->releaseLock($order_id);
    }
  }

}
