<?php

declare(strict_types=1);

namespace Drupal\commerce_novapay\Plugin\Field\FieldFormatter;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\commerce_payment\Entity\PaymentInterface;
use Drupal\commerce_price\Plugin\Field\FieldFormatter\PriceDefaultFormatter;

/**
 * Displays released authorizations without recording a monetary refund.
 */
final class NovaPayRefundAmountFormatter extends PriceDefaultFormatter {

  /**
   * {@inheritdoc}
   *
   * @phpstan-param \Drupal\Core\Field\FieldItemListInterface<\Drupal\Core\Field\FieldItemInterface> $items
   *
   * @return array<array-key, mixed>
   *   Formatted refund or released authorization amount.
   */
  public function viewElements(FieldItemListInterface $items, $langcode) {
    $payment = $items->getEntity();
    if ($payment instanceof PaymentInterface
      && $items->getName() === 'refunded_amount'
      && $payment->getState()->getId() === 'authorization_voided'
      && $payment->getPaymentGateway()?->getPluginId() === 'novapay') {
      $elements = parent::viewElements($payment->get('amount'), $langcode);
      CacheableMetadata::createFromObject($payment)->applyTo($elements);
      return $elements;
    }
    return parent::viewElements($items, $langcode);
  }

}
