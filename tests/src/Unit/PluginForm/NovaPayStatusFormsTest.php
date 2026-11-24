<?php

declare(strict_types=1);

namespace Drupal\Tests\commerce_novapay\Unit\PluginForm;

use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Component\Plugin\PluginInspectionInterface;
use Drupal\Core\Form\FormState;
use Drupal\Tests\UnitTestCase;
use Drupal\commerce_novapay\Payment\PaymentStatusCheckResult;
use Drupal\commerce_novapay\Payment\RefundStatusCheckResult;
use Drupal\commerce_novapay\Payment\SupportsItemRefundsInterface;
use Drupal\commerce_novapay\Payment\SupportsPaymentStatusChecksInterface;
use Drupal\commerce_novapay\PluginForm\NovaPayPaymentStatusForm;
use Drupal\commerce_novapay\PluginForm\NovaPayRefundStatusForm;
use Drupal\commerce_payment\Entity\PaymentInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests status-only forms report confirmation accurately and fail closed.
 */
#[CoversClass(NovaPayPaymentStatusForm::class)]
#[CoversClass(NovaPayRefundStatusForm::class)]
#[Group('commerce_novapay')]
final class NovaPayStatusFormsTest extends UnitTestCase {

  /**
   * Tests form notices and outcomes call only the status-check contract once.
   */
  #[DataProvider('statusProvider')]
  public function testStatusCheckOutcome(bool $refund, bool $confirmed): void {
    $payment = $this->createMock(PaymentInterface::class);
    /** @var \Drupal\Component\Plugin\PluginInspectionInterface&\PHPUnit\Framework\MockObject\MockObject $plugin */
    $plugin = $this->createMockForIntersectionOfInterfaces([
      PluginInspectionInterface::class,
      $refund ? SupportsItemRefundsInterface::class : SupportsPaymentStatusChecksInterface::class,
    ]);
    $method = $refund ? 'checkRefundStatus' : 'checkPaymentStatus';
    $result = $refund
      ? ($confirmed ? RefundStatusCheckResult::Confirmed : RefundStatusCheckResult::Pending)
      : ($confirmed ? PaymentStatusCheckResult::Reconciled : PaymentStatusCheckResult::Unchanged);
    $plugin->expects(self::once())->method($method)->with($payment)->willReturn($result);
    if ($refund) {
      $plugin->expects(self::never())->method('refundItems');
    }
    $form = $refund ? new NovaPayRefundStatusForm() : new NovaPayPaymentStatusForm();
    $form->setEntity($payment);
    $form->setPlugin($plugin);
    $translation = $this->getStringTranslationStub();
    self::assertInstanceOf(TranslationInterface::class, $translation);
    $form->setStringTranslation($translation);
    $state = new FormState();
    $build = $form->buildConfigurationForm([], $state);
    self::assertStringContainsString('does not submit', (string) $build['notice']['#markup']);
    $form->validateConfigurationForm($build, $state);
    $form->submitConfigurationForm($build, $state);
    $expected = $refund
      ? ($confirmed ? 'NovaPay confirmed the refund. Local payment totals were updated.' : 'NovaPay has not confirmed the refund yet. The refund remains pending.')
      : ($confirmed ? 'NovaPay payment status was reconciled.' : 'NovaPay has no conclusive status update for this payment.');
    self::assertSame($expected, (string) $build['#success_message']);
  }

  /**
   * Provides both operation types and conclusive/inconclusive outcomes.
   *
   * @return iterable<string, array{bool, bool}>
   *   Refund selector and confirmation flag.
   */
  public static function statusProvider(): iterable {
    yield 'refund confirmed' => [TRUE, TRUE];
    yield 'refund pending' => [TRUE, FALSE];
    yield 'payment reconciled' => [FALSE, TRUE];
    yield 'payment unchanged' => [FALSE, FALSE];
  }

  /**
   * Tests invalid entity/plugin binding never displays a success message.
   */
  #[DataProvider('bindingProvider')]
  public function testRejectsInvalidBinding(bool $refund, bool $has_payment): void {
    $form = $refund ? new NovaPayRefundStatusForm() : new NovaPayPaymentStatusForm();
    if ($has_payment) {
      $form->setEntity($this->createMock(PaymentInterface::class));
      $form->setPlugin($this->createMock(PluginInspectionInterface::class));
    }
    $build = [];
    try {
      $form->submitConfigurationForm($build, new FormState());
      self::fail('Invalid binding must reject the status operation.');
    }
    catch (\LogicException) {
      self::assertArrayNotHasKey('#success_message', $build);
    }
  }

  /**
   * Provides operation types and invalid entity/plugin bindings.
   *
   * @return iterable<string, array{bool, bool}>
   *   Refund selector and payment binding flag.
   */
  public static function bindingProvider(): iterable {
    yield 'refund without payment' => [TRUE, FALSE];
    yield 'refund unsupported plugin' => [TRUE, TRUE];
    yield 'payment without payment' => [FALSE, FALSE];
    yield 'payment unsupported plugin' => [FALSE, TRUE];
  }

}
