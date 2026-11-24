<?php

declare(strict_types=1);

namespace Drupal\commerce_novapay\Hook;

use Drupal\Core\Hook\Attribute\Hook;
use Drupal\commerce_novapay\Plugin\Field\FieldFormatter\NovaPayPaymentStatusFormatter;
use Drupal\commerce_novapay\Plugin\Field\FieldFormatter\NovaPayRefundAmountFormatter;
use Drupal\commerce_price\Plugin\Field\FieldFormatter\PriceDefaultFormatter;
use Drupal\options\Plugin\Field\FieldFormatter\OptionsDefaultFormatter;

/**
 * Integrates NovaPay status wording through the payment field formatter.
 */
final class PaymentFieldFormatterHooks {

  /**
   * Implements hook_field_formatter_info_alter().
   *
   * @param array<string, array<string, mixed>> $info
   *   The discovered field formatter definitions.
   */
  #[Hook('field_formatter_info_alter')]
  public function alterFormatters(array &$info): void {
    if (($info['commerce_price_default']['class'] ?? NULL) === PriceDefaultFormatter::class) {
      $info['commerce_price_default']['class'] = NovaPayRefundAmountFormatter::class;
    }
    if (($info['list_default']['class'] ?? NULL) === OptionsDefaultFormatter::class) {
      $info['list_default']['class'] = NovaPayPaymentStatusFormatter::class;
    }
  }

}
