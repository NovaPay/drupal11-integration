<?php

declare(strict_types=1);

namespace Drupal\commerce_novapay\Payment;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_order\OrderStorageInterface;
use Drupal\commerce_payment\Entity\PaymentInterface;
use Drupal\commerce_payment\Exception\PaymentGatewayException;

/**
 * Keeps distinct NovaPay sessions from overwriting the same order totals.
 */
final class PaymentOrderLock implements PaymentOrderLockInterface {

  use StringTranslationTrait;

  private const float LOCK_TIMEOUT_SECONDS = 120.0;

  /**
   * Constructs the order update lock.
   */
  public function __construct(
    private readonly LockBackendInterface $lock,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function acquire(PaymentInterface $payment): string {
    $order_id = $payment->getOrderId();
    if ($order_id === NULL || $order_id <= 0) {
      throw new \UnexpectedValueException('The NovaPay payment has no parent order.');
    }
    $name = 'commerce_novapay_order_update:' . $order_id;
    if (!$this->lock->acquire($name, self::LOCK_TIMEOUT_SECONDS)) {
      throw PaymentGatewayException::createForPayment($payment, (string) $this->t('Another NovaPay update is already being applied to this order. Try checking its status again.'));
    }
    try {
      $storage = $this->entityTypeManager->getStorage('commerce_order');
      if (!$storage instanceof OrderStorageInterface) {
        throw new \UnexpectedValueException('Commerce order storage is unavailable.');
      }
      $order = $storage->loadUnchanged($order_id);
      if (!$order instanceof OrderInterface) {
        throw new \UnexpectedValueException('The NovaPay payment has no parent order.');
      }
      // Discard cached sibling payments before total_paid is recomputed.
      $this->entityTypeManager->getStorage('commerce_payment')->resetCache();
      $payment->set('order_id', $order);
    }
    catch (\Throwable $exception) {
      $this->lock->release($name);
      throw $exception;
    }
    return $name;
  }

  /**
   * {@inheritdoc}
   */
  public function release(string $name): void {
    $this->lock->release($name);
  }

}
