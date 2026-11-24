<?php

declare(strict_types=1);

namespace Drupal\Tests\commerce_novapay\Kernel;

use Drupal\commerce_novapay\Credential\NovaPayMode;
use Drupal\commerce_novapay\Runtime\RuntimeProfile;
use Drupal\commerce_novapay\Runtime\RuntimeProfileStorageInterface;
use Drupal\commerce_novapay\Runtime\TransactionMode;
use Drupal\commerce_order\Adjustment;
use Drupal\commerce_order\Entity\Order;
use Drupal\commerce_order\Entity\OrderItem;
use Drupal\commerce_payment\Entity\Payment;
use Drupal\commerce_payment\Entity\PaymentGateway;
use Drupal\commerce_payment\Entity\PaymentInterface;
use Drupal\commerce_price\Price;
use Drupal\Tests\commerce_order\Kernel\OrderKernelTestBase;
use GuzzleHttp\ClientInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Exercises the CLI script with isolated carts and no network requests.
 */
#[Group('commerce_novapay')]
final class RefundSimulationScriptTest extends OrderKernelTestBase {

  /**
   * {@inheritdoc}
   *
   * @var list<string>
   */
  protected static $modules = [
    'commerce_cart', 'commerce_checkout', 'commerce_payment',
    'commerce_novapay', 'telephone',
  ];

  /**
   * Tests signed callbacks, refund totals, rollback and repeat-run protection.
   */
  #[DataProvider('scenarioProvider')]
  public function testSimulation(string $type, string $quantity, ?string $expected_refund, bool $shipping, ?string $capture = NULL): void {
    $this->installEntitySchema('commerce_payment');
    $this->installSchema('commerce_novapay', ['commerce_novapay_postback_event', 'commerce_novapay_refund_ledger']);
    $this->installConfig(['commerce_payment']);
    $this->container->get('commerce_price.currency_importer')->import('UAH');
    $gateway = PaymentGateway::create(['id' => 'simulation', 'label' => 'Simulation', 'plugin' => 'novapay']);
    $gateway->save();

    $profiles = $this->createMock(RuntimeProfileStorageInterface::class);
    $profiles->method('load')->with($gateway->uuid())->willReturn(
      new RuntimeProfile(
        NovaPayMode::Test,
        'offline-test',
        TransactionMode::Direct,
        '',
        FALSE,
      ),
    );
    $this->container->set('commerce_novapay.runtime_profile_storage', $profiles);
    $item = OrderItem::create([
      'type' => 'test', 'title' => 'Simulation item', 'quantity' => $quantity,
      'unit_price' => new Price('7.50', 'UAH'),
    ]);
    $item->save();
    $order = Order::create([
      'type' => 'default', 'store_id' => $this->store->id(), 'state' => 'draft',
      'cart' => TRUE, 'order_items' => [$item],
    ]);
    if ($shipping) {
      $order->addAdjustment(new Adjustment([
        'type' => 'custom', 'label' => 'Shipping allocation',
        'amount' => new Price('5', 'UAH'), 'locked' => TRUE,
      ]));
    }
    $order->save();
    $expected_authorization = $order->getTotalPrice();
    $http = $this->createMock(ClientInterface::class);
    $http->expects(self::never())->method('request');
    $this->container->set('http_client', $http);
    // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- Arguments are consumed by the included Drush script.
    $extra = ['--order-id=' . $order->id(), '--refund-type=' . $type];
    if ($capture !== NULL) {
      $extra[] = '--capture-amount=' . $capture;
    }
    $script = dirname(__DIR__, 3) . '/scripts/simulate-refund.php';
    $output = NULL;
    ob_start();
    try {
      include $script;
      $output = ob_get_contents();
    }
    catch (\RuntimeException | \InvalidArgumentException $exception) {
      if ($expected_refund !== NULL) {
        throw $exception;
      }
      self::assertStringContainsString($type === 'full' ? 'CAPTURE_AMOUNT' : 'Partial refund needs', $exception->getMessage());
    }
    finally {
      ob_end_clean();
    }
    $storage = $this->container->get('entity_type.manager')->getStorage('commerce_payment');
    $storage->resetCache();
    $payments = $storage->loadByProperties(['order_id' => $order->id()]);
    if ($expected_refund === NULL) {
      self::assertSame([], $payments);
      $fresh_cart = $this->container->get('entity_type.manager')->getStorage('commerce_order')->loadUnchanged($order->id());
      self::assertInstanceOf(Order::class, $fresh_cart);
      self::assertSame('draft', $fresh_cart->getState()->getId());
      self::assertTrue((bool) $fresh_cart->get('cart')->value);
      self::assertEquals($expected_authorization, $fresh_cart->getTotalPrice());
      self::assertSame('0', (string) $this->container->get('database')->select('commerce_novapay_postback_event')->countQuery()->execute()->fetchField());
      return;
    }
    self::assertCount(1, $payments);
    $payment = reset($payments);
    self::assertInstanceOf(PaymentInterface::class, $payment);
    self::assertEquals(new Price($expected_refund, 'UAH'), $payment->getRefundedAmount());
    self::assertSame($type === 'full' ? 'refunded' : 'partially_refunded', $payment->getState()->getId());
    self::assertTrue($payment->get('novapay_pending_operation')->isEmpty());
    self::assertIsString($output);
    $result = json_decode($output, TRUE, 512, JSON_THROW_ON_ERROR);
    self::assertTrue($result['simulation']);
    $fresh_order = $this->container->get('entity_type.manager')->getStorage('commerce_order')->loadUnchanged($result['order_id']);
    self::assertInstanceOf(Order::class, $fresh_order);
    self::assertNotSame('draft', $fresh_order->getState()->getId());
    self::assertFalse((bool) $fresh_order->get('cart')->value);
    self::assertSame('simulation', $fresh_order->get('payment_gateway')->target_id);
    if ($type === 'partial') {
      self::assertEquals($payment->getAmount()->subtract($payment->getRefundedAmount()), $fresh_order->getTotalPrice());
      self::assertTrue($fresh_order->getBalance()->isZero());
      self::assertTrue($fresh_order->isPaid());
      self::assertCount(1, $fresh_order->getAdjustments(['novapay_partial_refund']));
    }
    self::assertEquals($expected_refund, $result['refunded_amount']);
    self::assertSame($capture === NULL ? 'direct' : 'hold', $result['payment_type']);
    if ($capture !== NULL) {
      self::assertGreaterThan(0, $payment->getAuthorizedTime());
      self::assertEquals(new Price($capture, 'UAH'), $payment->getAmount());
      self::assertEquals($expected_authorization->getNumber(), $result['authorized_amount']);
      self::assertStringEndsWith('-capture', $payment->get('novapay_operation_id')->getString());
      self::assertCount($expected_authorization->equals(new Price($capture, 'UAH')) ? 0 : 1, $fresh_order->getAdjustments(['novapay_partial_capture']));
      if (!$expected_authorization->equals(new Price($capture, 'UAH'))) {
        self::assertStringEndsWith('-payment', $result['capture_request']['operations'][0]['id']);
        self::assertEquals($capture, $result['capture_request']['operations'][0]['amount']);
      }
      if ($type === 'partial') {
        self::assertSame($payment->get('novapay_operation_id')->getString(), $result['refund_request']['operations'][0]['id']);
        self::assertEquals($expected_refund, $result['refund_request']['operations'][0]['refund_amount']);
      }
      else {
        self::assertArrayNotHasKey('operations', $result['refund_request']);
      }
    }
    ob_start();
    try {
      include $script;
      self::fail('A simulated cart cannot receive another payment.');
    }
    catch (\RuntimeException $exception) {
      self::assertStringContainsString('fresh cart', $exception->getMessage());
    }
    finally {
      ob_end_clean();
    }
  }

  /**
   * Tests offline refunds reuse a test payment and preserve its identity.
   */
  #[DataProvider('existingPaymentProvider')]
  public function testExistingPayment(string $scenario, ?string $refund = NULL): void {
    $this->installEntitySchema('commerce_payment');
    $this->installSchema('commerce_novapay', ['commerce_novapay_postback_event', 'commerce_novapay_refund_ledger']);
    $this->installConfig(['commerce_payment']);
    $this->container->get('commerce_price.currency_importer')->import('UAH');
    $gateway = PaymentGateway::create([
      'id' => 'existing', 'label' => 'Existing', 'plugin' => 'novapay',
      'configuration' => ['mode' => $scenario === 'stale technical mode' ? 'test' : 'n/a'],
    ]);
    $gateway->save();
    $profiles = $this->createMock(RuntimeProfileStorageInterface::class);
    $profiles->method('load')->with($gateway->uuid())->willReturn(
      $scenario === 'missing profile' ? NULL : new RuntimeProfile(
        in_array($scenario, ['live gateway', 'stale technical mode'], TRUE) ? NovaPayMode::Live : NovaPayMode::Test,
        'offline-test', TransactionMode::Direct, '', FALSE,
      ),
    );
    $this->container->set('commerce_novapay.runtime_profile_storage', $profiles);
    $item = OrderItem::create([
      'type' => 'test', 'title' => 'Existing item', 'quantity' => '4',
      'unit_price' => new Price('5', 'UAH'),
    ]);
    $item->save();
    $existing_order = Order::create([
      'type' => 'default', 'store_id' => $this->store->id(),
      'state' => 'completed', 'cart' => FALSE, 'order_items' => [$item],
      'payment_gateway' => $gateway,
    ]);
    $hold = in_array($scenario, ['hold', 'no partial quantity', 'amount hold', 'amount exact', 'amount excessive'], TRUE);
    if ($hold) {
      $existing_order->addAdjustment(new Adjustment([
        'type' => 'novapay_partial_capture', 'label' => 'Capture adjustment',
        'amount' => new Price('-10', 'UAH'), 'locked' => TRUE,
      ]));
    }
    $existing_order->save();
    $existing = Payment::create([
      'payment_gateway' => $gateway, 'order_id' => $existing_order->id(),
      'amount' => new Price($hold ? '10' : '20', 'UAH'),
      'state' => $scenario === 'authorization' ? 'authorization' : 'completed',
      'remote_id' => 'sandbox-existing-session', 'remote_state' => $hold ? 'hold_confirmed' : 'paid',
      'novapay_operation_id' => 'sandbox-existing-operation',
      'novapay_external_id' => (string) $existing_order->id(),
    ]);
    if ($hold) {
      $existing->setAuthorizedTime(time());
    }
    if ($scenario === 'pending') {
      $existing->set('novapay_pending_operation', 'refund');
    }
    if ($scenario === 'live payment') {
      $existing->set('payment_gateway_mode', 'live');
    }
    if ($scenario === 'missing operation') {
      $existing->set('novapay_operation_id', NULL);
    }
    if ($scenario === 'missing session') {
      $existing->setRemoteId('');
    }
    $existing->save();
    if (in_array($scenario, ['ambiguous', 'explicit'], TRUE)) {
      $sibling = $existing->createDuplicate();
      $sibling->setRemoteId('sandbox-sibling-session');
      $sibling->save();
    }
    if ($scenario === 'no partial quantity') {
      $item->setUnitPrice(new Price('10', 'UAH'))->save();
    }
    $http = $this->createMock(ClientInterface::class);
    $http->expects(self::never())->method('request');
    $this->container->set('http_client', $http);
    $args = ['--order-id=' . $existing_order->id(), '--refund-type=partial', '--existing'];
    if ($scenario === 'explicit') {
      $args[] = '--payment-id=' . $existing->id();
    }
    if ($scenario === 'wrong payment') {
      $args[] = '--payment-id=999999';
    }
    if ($refund !== NULL) {
      $args = ['--order-id=' . $existing_order->id(), '--payment-id=' . $existing->id(), '--refund-amount=' . $refund];
    }
    $run = static function (array $args): array {
      // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- Drush arguments for the included script.
      $extra = $args;
      ob_start();
      try {
        include dirname(__DIR__, 3) . '/scripts/simulate-refund.php';
        return json_decode((string) ob_get_contents(), TRUE, 512, JSON_THROW_ON_ERROR);
      }
      finally {
        ob_end_clean();
      }
    };
    $storage = $this->container->get('entity_type.manager')->getStorage('commerce_payment');
    $expected_count = in_array($scenario, ['ambiguous', 'explicit'], TRUE) ? 2 : 1;
    $success = in_array($scenario, ['direct', 'hold', 'explicit', 'amount direct', 'amount hold', 'amount exact'], TRUE);
    try {
      $result = $run($args);
      self::assertTrue($success, 'Invalid existing payment must be rejected.');
    }
    catch (\RuntimeException | \InvalidArgumentException $exception) {
      if ($success) {
        throw $exception;
      }
      $unchanged = $storage->loadUnchanged($existing->id());
      self::assertInstanceOf(PaymentInterface::class, $unchanged);
      self::assertCount($expected_count, $storage->loadByProperties(['order_id' => $existing_order->id()]));
      self::assertSame('0', (string) $this->container->get('database')->select('commerce_novapay_postback_event')->countQuery()->execute()->fetchField());
      self::assertTrue($unchanged->getRefundedAmount()->isZero());
      self::assertSame($existing->getState()->getId(), $unchanged->getState()->getId());
      self::assertSame($existing->get('novapay_pending_operation')->getString(), $unchanged->get('novapay_pending_operation')->getString());
      self::assertSame('0', (string) $this->container->get('database')->select('commerce_novapay_refund_ledger')->countQuery()->execute()->fetchField());
      return;
    }
    if ($refund !== NULL) {
      self::assertEquals($refund, $result['refunded_amount']);
      self::assertSame([], $result['capture_request']);
      self::assertSame((string) $existing->id(), $result['payment_id']);
      self::assertCount(1, $storage->loadByProperties(['order_id' => $existing_order->id()]));
      $fresh_amount_payment = $storage->loadUnchanged($existing->id());
      self::assertInstanceOf(PaymentInterface::class, $fresh_amount_payment);
      self::assertEquals($existing->getAmount(), $fresh_amount_payment->getAmount());
      self::assertSame($existing->getRemoteId(), $fresh_amount_payment->getRemoteId());
      if ($result['refund_type'] === 'partial') {
        self::assertEquals($refund, $result['refund_request']['operations'][0]['refund_amount']);
        self::assertSame('sandbox-existing-operation', $result['refund_request']['operations'][0]['id']);
        self::assertEquals('0', $result['order_balance']);
        $items = $this->container->get('commerce_novapay.refund_operation_manager')->getRefundableItems($fresh_amount_payment);
        self::assertSame('4', $items[0]->getAvailableQuantity());
        $second_amount = $run($args);
        self::assertEquals((new Price($refund, 'UAH'))->multiply('2')->getNumber(), $second_amount['refunded_amount']);
        $remaining = $existing->getAmount()->subtract(new Price($second_amount['refunded_amount'], 'UAH'));
        $args[2] = '--refund-amount=' . $remaining->getNumber();
        $result = $run($args);
      }
      self::assertSame('refunded', $result['payment_state']);
      self::assertArrayNotHasKey('operations', $result['refund_request']);
      self::assertEquals($existing->getAmount()->getNumber(), $result['refunded_amount']);
      return;
    }
    self::assertTrue($result['existing_payment']);
    self::assertSame((string) $existing->id(), $result['payment_id']);
    self::assertSame([], $result['capture_request']);
    self::assertEquals('5', $result['refunded_amount']);
    self::assertSame('sandbox-existing-operation', $result['refund_request']['operations'][0]['id']);
    self::assertCount($expected_count, $storage->loadByProperties(['order_id' => $existing_order->id()]));
    $fresh = $storage->loadUnchanged($existing->id());
    self::assertInstanceOf(PaymentInterface::class, $fresh);
    self::assertSame('sandbox-existing-session', $fresh->getRemoteId());
    self::assertSame('sandbox-existing-operation', $fresh->get('novapay_operation_id')->getString());
    self::assertSame('partially_refunded', $fresh->getState()->getId());
    self::assertEquals($existing->getAmount(), $fresh->getAmount());
    if ($scenario !== 'explicit') {
      self::assertEquals('0', $result['order_balance']);
    }
    if ($scenario === 'direct') {
      $second = $run($args);
      self::assertEquals('10', $second['refunded_amount']);
      self::assertEquals('0', $second['order_balance']);
    }
    $args[1] = '--refund-type=full';
    $final = $run($args);
    self::assertSame('refunded', $final['payment_state']);
    self::assertEquals($hold ? '10' : '20', $final['refunded_amount']);
    self::assertArrayNotHasKey('operations', $final['refund_request']);
    self::assertCount($expected_count, $storage->loadByProperties(['order_id' => $existing_order->id()]));
  }

  /**
   * Provides eligible, ambiguous and ineligible existing payment scenarios.
   *
   * @return iterable<string, array{string, 1?: string}>
   *   Existing payment cases.
   */
  public static function existingPaymentProvider(): iterable {
    foreach ([
      'direct', 'hold', 'explicit', 'ambiguous', 'live gateway', 'live payment',
      'pending', 'authorization', 'missing operation', 'missing session',
      'wrong payment', 'no partial quantity', 'stale technical mode', 'missing profile',
    ] as $scenario) {
      yield $scenario => [$scenario];
    }
    yield 'monetary direct' => ['amount direct', '2.50'];
    yield 'monetary partial capture' => ['amount hold', '2.50'];
    yield 'monetary full balance' => ['amount exact', '10.00'];
    yield 'monetary excessive' => ['amount excessive', '10.01'];
    yield 'monetary zero' => ['amount zero', '0'];
    yield 'monetary negative' => ['amount negative', '-1'];
    yield 'monetary sub-cent' => ['amount sub-cent', '2.501'];
  }

  /**
   * Rejects invalid options before accessing or changing order data.
   *
   * @param list<string> $options
   *   Invalid command options.
   */
  #[DataProvider('invalidOptionsProvider')]
  public function testInvalidOptions(array $options): void {
    // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- Drush arguments for the included script.
    $extra = $options;
    $this->expectException(\InvalidArgumentException::class);
    include dirname(__DIR__, 3) . '/scripts/simulate-refund.php';
  }

  /**
   * Provides malformed, duplicate, missing and conflicting options.
   *
   * @return iterable<string, array{list<string>}>
   *   Rejected option lists.
   */
  public static function invalidOptionsProvider(): iterable {
    $valid = ['--order-id=56', '--refund-type=partial'];
    yield 'missing all' => [[]];
    yield 'missing type' => [['--order-id=56']];
    yield 'missing order' => [['--refund-type=partial']];
    yield 'invalid ID' => [['--order-id=0', '--refund-type=partial']];
    yield 'invalid type' => [['--order-id=56', '--refund-type=other']];
    yield 'positional' => [['56', 'partial']];
    yield 'unknown' => [[...$valid, '--other=1']];
    yield 'duplicate ID' => [[...$valid, '--order-id=57']];
    yield 'duplicate flag' => [[...$valid, '--existing', '--existing']];
    yield 'empty value' => [[...$valid, '--capture-amount=']];
    yield 'conflicting capture' => [[...$valid, '--existing', '--capture-amount=10']];
    yield 'payment without existing' => [[...$valid, '--payment-id=1']];
    yield 'invalid payment ID' => [[...$valid, '--existing', '--payment-id=-1']];
    yield 'amount without payment' => [['--order-id=56', '--refund-amount=5']];
    yield 'amount with type' => [[...$valid, '--payment-id=85', '--refund-amount=5']];
    yield 'amount with capture' => [['--order-id=56', '--payment-id=85', '--refund-amount=5', '--capture-amount=10']];
  }

  /**
   * Tests partial refund for multiple specific items using --items option.
   */
  public function testCustomItemsPartialRefund(): void {
    $this->installEntitySchema('commerce_payment');
    $this->installSchema('commerce_novapay', ['commerce_novapay_postback_event', 'commerce_novapay_refund_ledger']);
    $this->installConfig(['commerce_payment']);
    $this->container->get('commerce_price.currency_importer')->import('UAH');
    $gateway = PaymentGateway::create(['id' => 'simulation', 'label' => 'Simulation', 'plugin' => 'novapay']);
    $gateway->save();

    $profiles = $this->createMock(RuntimeProfileStorageInterface::class);
    $profiles->method('load')->with($gateway->uuid())->willReturn(
      new RuntimeProfile(
        NovaPayMode::Test,
        'offline-test',
        TransactionMode::Direct,
        '',
        FALSE,
      ),
    );
    $this->container->set('commerce_novapay.runtime_profile_storage', $profiles);

    $item1 = OrderItem::create([
      'type' => 'test',
      'title' => 'Item 1',
      'quantity' => '2',
      'unit_price' => new Price('10.00', 'UAH'),
    ]);
    $item1->save();
    $item2 = OrderItem::create([
      'type' => 'test',
      'title' => 'Item 2',
      'quantity' => '3',
      'unit_price' => new Price('20.00', 'UAH'),
    ]);
    $item2->save();

    $order = Order::create([
      'type' => 'default',
      'store_id' => $this->store->id(),
      'state' => 'completed',
      'cart' => FALSE,
      'order_items' => [$item1, $item2],
      'payment_gateway' => $gateway,
    ]);
    $order->save();

    $payment = Payment::create([
      'payment_gateway' => $gateway,
      'order_id' => $order->id(),
      'amount' => new Price('80.00', 'UAH'),
      'state' => 'completed',
      'remote_id' => 'sandbox-multi-item-session',
      'remote_state' => 'paid',
      'novapay_operation_id' => 'sandbox-multi-item-op',
      'novapay_external_id' => (string) $order->id(),
    ]);
    $payment->save();

    $http = $this->createMock(ClientInterface::class);
    $http->expects(self::never())->method('request');
    $this->container->set('http_client', $http);

    // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- Arguments consumed by script.
    $extra = [
      '--order-id=' . $order->id(),
      '--payment-id=' . $payment->id(),
      '--items=' . $item1->id() . ':1,' . $item2->id() . ':1',
    ];

    $script = dirname(__DIR__, 3) . '/scripts/simulate-refund.php';
    ob_start();
    try {
      include $script;
      $output = ob_get_contents();
    }
    finally {
      ob_end_clean();
    }

    self::assertIsString($output);
    $result = json_decode($output, TRUE, 512, JSON_THROW_ON_ERROR);
    self::assertTrue($result['simulation']);
    self::assertSame('partial', $result['refund_type']);
    self::assertSame('30', (string) (float) $result['refunded_amount']);

    $ledger = $this->container->get('commerce_novapay.refund_ledger');
    $refunded_quantities = $ledger->getRefundedQuantities((int) $payment->id());
    self::assertSame('1', $refunded_quantities[(int) $item1->id()]);
    self::assertSame('1', $refunded_quantities[(int) $item2->id()]);
  }

  /**
   * Provides full, partial and impossible partial refund scenarios.
   *
   * @return iterable<string, array{string, string, ?string, bool, ?string}>
   *   Refund type, item quantity and expected refunded amount.
   */
  public static function scenarioProvider(): iterable {
    yield 'full' => ['full', '2', '15', FALSE, NULL];
    yield 'partial' => ['partial', '2', '7.5', FALSE, NULL];
    yield 'indivisible item' => ['partial', '1', NULL, FALSE, NULL];
    yield 'single item plus shipping' => ['partial', '1', NULL, TRUE, NULL];
    yield 'partial capture partial refund' => ['partial', '3', '7.5', FALSE, '10'];
    yield 'partial capture full refund' => ['full', '2', '10', FALSE, '10'];
    yield 'full capture partial refund' => ['partial', '2', '7.5', FALSE, '15'];
    yield 'capture below minimum item refund' => ['partial', '2', NULL, FALSE, '5'];
    yield 'capture equals minimum item refund' => ['partial', '2', NULL, FALSE, '7.50'];
    yield 'capture above cart total' => ['full', '2', NULL, FALSE, '15.01'];
    yield 'zero capture' => ['full', '2', NULL, FALSE, '0'];
    yield 'negative capture' => ['full', '2', NULL, FALSE, '-1'];
    yield 'sub-cent capture' => ['full', '2', NULL, FALSE, '10.001'];
    yield 'malformed capture' => ['full', '2', NULL, FALSE, 'invalid'];
  }

}
