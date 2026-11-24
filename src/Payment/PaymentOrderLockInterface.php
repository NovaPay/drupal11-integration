<?php

declare(strict_types=1);

namespace Drupal\commerce_novapay\Payment;

use Drupal\commerce_payment\Entity\PaymentInterface;

/**
 * Serializes NovaPay order updates before their database transaction starts.
 */
interface PaymentOrderLockInterface {

  /**
   * Locks and reloads the parent order, returning the acquired lock name.
   */
  public function acquire(PaymentInterface $payment): string;

  /**
   * Releases a lock after the caller commits or rolls back its transaction.
   */
  public function release(string $name): void;

}
