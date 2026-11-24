<?php

declare(strict_types=1);

namespace Drupal\Tests\commerce_novapay\Unit\Payment;

use Drupal\Tests\UnitTestCase;
use Drupal\commerce_novapay\Payment\PaymentStatusDisplay;
use Drupal\commerce_payment\Entity\PaymentInterface;
use Drupal\state_machine\Plugin\Field\FieldType\StateItemInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests refund display without modifying financial states.
 */
#[CoversClass(PaymentStatusDisplay::class)]
#[Group('commerce_novapay')]
final class PaymentStatusDisplayTest extends UnitTestCase {

  /**
   * Tests confirmed refunds share a label while completed payments remain paid.
   */
  public function testRefundLabels(): void {
    $display = new PaymentStatusDisplay();
    /** @var \Drupal\Core\StringTranslation\TranslationInterface $translation */
    $translation = $this->getStringTranslationStub();
    $display->setStringTranslation($translation);
    foreach ([
      'completed' => 'Paid',
      'partially_refunded' => 'Refunded',
      'refunded' => 'Refunded',
      'authorization_voided' => 'Refunded',
    ] as $id => $expected) {
      $state = $this->createMock(StateItemInterface::class);
      $state->method('getId')->willReturn($id);
      $payment = $this->createMock(PaymentInterface::class);
      $payment->method('getState')->willReturn($state);
      $payment->method('getRemoteState')->willReturn('paid');
      $payment->expects(self::never())->method('save');
      self::assertSame($expected, $display->label($payment));
    }
  }

}
