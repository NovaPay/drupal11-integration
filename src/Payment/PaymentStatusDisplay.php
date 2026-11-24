<?php

declare(strict_types=1);

namespace Drupal\commerce_novapay\Payment;

use Drupal\commerce_payment\Entity\PaymentInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * Displays fixed specification labels without changing financial state.
 */
final class PaymentStatusDisplay {

  use StringTranslationTrait;

  /**
   * Returns a specification label with a module-specific translation context.
   */
  public function label(PaymentInterface $payment): string {
    $options = ['context' => 'NovaPay payment status'];
    // Financial state takes precedence over the session's last remote state.
    return (string) match ($payment->getState()->getId()) {
      'completed' => $payment->getRemoteState() === 'hold_confirmed'
        ? $this->t('Completed', [], $options)
        : $this->t('Paid', [], $options),
      'authorization' => $this->t('On hold', [], $options),
      'partially_refunded', 'refunded', 'authorization_voided' => $this->t('Refunded', [], $options),
      'failed' => $this->t('Failed', [], $options),
      'expired' => $this->t('Expired', [], $options),
      'pending' => $payment->getRemoteState() === 'created'
        ? $this->t('Processing', [], $options)
        : $this->t('Pending payment', [], $options),
      default => '',
    };
  }

}
