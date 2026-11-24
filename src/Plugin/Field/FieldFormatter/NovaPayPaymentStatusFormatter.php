<?php

declare(strict_types=1);

namespace Drupal\commerce_novapay\Plugin\Field\FieldFormatter;

use Drupal\Core\Field\FieldItemListInterface;
use Drupal\commerce_novapay\Payment\PaymentStatusDisplay;
use Drupal\commerce_payment\Entity\PaymentInterface;
use Drupal\options\Plugin\Field\FieldFormatter\OptionsDefaultFormatter;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Formats NovaPay payment states while preserving other gateways' labels.
 */
final class NovaPayPaymentStatusFormatter extends OptionsDefaultFormatter {

  /**
   * The shared NovaPay payment status formatter.
   */
  private PaymentStatusDisplay $statusDisplay;

  /**
   * {@inheritdoc}
   *
   * @phpstan-param array<string, mixed> $configuration
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->statusDisplay = $container->get('commerce_novapay.payment_status_display');
    return $instance;
  }

  /**
   * {@inheritdoc}
   *
   * @phpstan-param \Drupal\Core\Field\FieldItemListInterface<\Drupal\Core\Field\FieldItemInterface> $items
   *
   * @return array<array-key, mixed>
   *   Localized payment state elements.
   */
  public function viewElements(FieldItemListInterface $items, $langcode) {
    $payment = $items->getEntity();
    if (!$payment instanceof PaymentInterface
      || $items->getName() !== 'state'
      || $payment->getPaymentGateway()?->getPluginId() !== 'novapay') {
      return parent::viewElements($items, $langcode);
    }
    $elements = ['#cache' => ['contexts' => ['languages:language_interface']]];
    foreach ($items as $delta => $item) {
      $elements[$delta] = ['#plain_text' => $this->statusDisplay->label($payment)];
    }
    return $elements;
  }

}
