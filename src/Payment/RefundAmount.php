<?php

declare(strict_types=1);

namespace Drupal\commerce_novapay\Payment;

use Drupal\commerce_payment\Exception\InvalidRequestException;
use Drupal\commerce_price\Price;

/**
 * Parses exact UAH compensation amounts without rounding or floating point.
 */
final class RefundAmount {

  /**
   * Requires a positive decimal string with at most two fractional digits.
   */
  public static function parse(mixed $value): Price {
    $currency = $value instanceof Price ? $value->getCurrencyCode() : 'UAH';
    $number = $value instanceof Price ? $value->getNumber() : $value;
    if (
      $currency !== 'UAH'
      || !is_string($number)
      || preg_match('/^(?:0|[1-9][0-9]{0,10})(?:\.[0-9]{1,2})?$/D', $number) !== 1
    ) {
      throw new InvalidRequestException('Enter a positive UAH refund amount with at most two decimal places.');
    }
    $amount = new Price($number, 'UAH');
    if (!$amount->isPositive()) {
      throw new InvalidRequestException('Enter a positive UAH refund amount with at most two decimal places.');
    }
    return $amount;
  }

}
