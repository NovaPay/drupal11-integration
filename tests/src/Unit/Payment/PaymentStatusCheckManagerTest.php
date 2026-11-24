<?php

declare(strict_types=1);

namespace Drupal\Tests\commerce_novapay\Unit\Payment;

use Drupal\commerce_novapay\Payment\PaymentOrderLockInterface;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\commerce_novapay\Api\Dto\Response\SessionStatusResponse;
use Drupal\commerce_novapay\Api\NovaPayApiClientInterface;
use Drupal\commerce_novapay\Exception\ApiTransportException;
use Drupal\commerce_novapay\Payment\PaymentStatusCheckManager;
use Drupal\commerce_novapay\Postback\PaymentStatusMapperInterface;
use Drupal\commerce_novapay\Runtime\RuntimeConfigurationProviderInterface;
use Drupal\commerce_payment\Entity\PaymentInterface;
use Drupal\commerce_payment\Exception\PaymentGatewayException;
use Drupal\commerce_payment\PaymentStorageInterface;
use Drupal\state_machine\Plugin\Field\FieldType\StateItemInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests read-only reconciliation guards and lock release on uncertain status.
 */
#[CoversClass(PaymentStatusCheckManager::class)]
#[Group('commerce_novapay')]
final class PaymentStatusCheckManagerTest extends UnitTestCase {

  /**
   * Tests unsafe local/remote status cannot reach the payment status mapper.
   */
  #[DataProvider('failureProvider')]
  public function testFailureCannotMutatePayment(string $failure, string $message): void {
    $payment = $this->createMock(PaymentInterface::class);
    $payment->method('id')->willReturn($failure === 'unsaved' ? NULL : '7');
    $payment->method('getRemoteId')->willReturn($failure === 'no session' ? '' : 'session-1');
    $payment->method('getPaymentGatewayId')->willReturn('novapay');
    $current = $this->createMock(PaymentInterface::class);
    $current->method('getPaymentGatewayId')->willReturn($failure === 'wrong gateway' ? 'other' : 'novapay');
    $current->method('bundle')->willReturn($failure === 'wrong bundle' ? 'payment_default' : 'novapay_payment');
    $current->method('getRemoteId')->willReturn($failure === 'changed session' ? 'session-2' : 'session-1');
    $state = $this->createMock(StateItemInterface::class);
    $state->method('getId')->willReturn($failure === 'terminal state' ? 'completed' : 'pending');
    $current->method('getState')->willReturn($state);
    $current->expects(self::never())->method('save');
    $storage = $this->createMock(PaymentStorageInterface::class);
    $storage->method('load')->willReturn($failure === 'missing payment' ? NULL : $current);
    $entities = $this->createMock(EntityTypeManagerInterface::class);
    $entities->method('getStorage')->with('commerce_payment')->willReturn($storage);
    $lock = $this->createMock(LockBackendInterface::class);
    $lock->method('acquire')->willReturn($failure !== 'locked');
    $before_lock = in_array($failure, ['unsaved', 'no session', 'locked'], TRUE);
    $lock->expects($before_lock ? self::never() : self::once())->method('release');
    $api = $this->createMock(NovaPayApiClientInterface::class);
    foreach (['createSession', 'addPayment', 'completeHold', 'voidPayment'] as $method) {
      $api->expects(self::never())->method($method);
    }
    if ($failure === 'HTTP failure') {
      $api->expects(self::once())->method('getStatus')->willThrowException(ApiTransportException::requestFailed());
    }
    elseif ($failure === 'foreign response') {
      $api->expects(self::once())->method('getStatus')->willReturn(SessionStatusResponse::fromArray([
        'id' => 'foreign',
        'status' => 'paid',
      ]));
    }
    else {
      $api->expects(self::never())->method('getStatus');
    }
    $mapper = $this->createMock(PaymentStatusMapperInterface::class);
    $mapper->expects(self::never())->method('apply');
    $translation = $this->getStringTranslationStub();
    self::assertInstanceOf(TranslationInterface::class, $translation);
    $manager = new PaymentStatusCheckManager($entities, $api, $mapper, $lock, $this->createMock(Connection::class), $this->createMock(PaymentOrderLockInterface::class));
    $manager->setStringTranslation($translation);
    $this->expectException(PaymentGatewayException::class);
    $this->expectExceptionMessage($message);
    $manager->checkStatus($payment, $this->createMock(RuntimeConfigurationProviderInterface::class));
  }

  /**
   * Provides local consistency guards and remote failures.
   *
   * @return iterable<string, array{string, string}>
   *   Failure selector and bounded error text.
   */
  public static function failureProvider(): iterable {
    yield 'unsaved' => ['unsaved', 'must be saved'];
    yield 'no session' => ['no session', 'session ID is unavailable'];
    yield 'locked' => ['locked', 'already being processed'];
    yield 'wrong gateway' => ['wrong gateway', 'could not be reloaded safely'];
    yield 'wrong bundle' => ['wrong bundle', 'could not be reloaded safely'];
    yield 'missing payment' => ['missing payment', 'could not be reloaded safely'];
    yield 'terminal state' => ['terminal state', 'cannot be reconciled'];
    yield 'changed session' => ['changed session', 'session ID changed'];
    yield 'HTTP failure' => ['HTTP failure', 'status could not be checked'];
    yield 'foreign response' => ['foreign response', 'unexpected session status'];
  }

}
