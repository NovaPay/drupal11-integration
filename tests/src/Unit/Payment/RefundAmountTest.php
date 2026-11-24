<?php

declare(strict_types=1);

namespace Drupal\Tests\commerce_novapay\Unit\Payment;

use Drupal\commerce_novapay\Payment\RefundAmount;
use Drupal\commerce_payment\Exception\InvalidRequestException;
use Drupal\commerce_price\Price;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the exact monetary trust boundary.
 */
#[CoversClass(RefundAmount::class)]
#[Group('commerce_novapay')]
final class RefundAmountTest extends UnitTestCase {

  /**
   * Tests supported decimal strings without rounding.
   */
  public function testExactAmounts(): void {
    foreach (['0.01', '1', '200.00', '600.01'] as $number) {
      self::assertEquals(new Price($number, 'UAH'), RefundAmount::parse($number));
    }
  }

  /**
   * Tests formats and currencies that must never reach the API.
   */
  public function testInvalidAmounts(): void {
    foreach ([NULL, 1, 1.5, '0', '-1', '1.001', '1e2', 'NaN', 'Infinity', ' 1', '1,5', new Price('1', 'USD')] as $value) {
      try {
        RefundAmount::parse($value);
        self::fail('Invalid compensation accepted.');
      }
      catch (InvalidRequestException $exception) {
        self::assertStringContainsString('UAH', $exception->getMessage());
      }
    }
  }

}
