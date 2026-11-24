<?php

declare(strict_types=1);

namespace Drupal\Tests\commerce_novapay\Kernel;

use Drupal\commerce_novapay\Credential\NovaPayMode;
use Drupal\commerce_novapay\Runtime\RuntimeProfile;
use Drupal\commerce_novapay\Runtime\RuntimeProfileStorageInterface;
use Drupal\commerce_novapay\Runtime\TransactionMode;
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
 * Exercises the simulate-payment CLI script with isolated carts and no network.
 */
#[Group('commerce_novapay')]
final class PaymentSimulationScriptTest extends OrderKernelTestBase {

  /**
   * {@inheritdoc}
   *
   * @var list<string>
   */
  protected static $modules = [
    'commerce_cart',
    'commerce_checkout',
    'commerce_payment',
    'commerce_novapay',
    'telephone',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('commerce_payment');
    $this->installSchema('commerce_novapay', ['commerce_novapay_postback_event', 'commerce_novapay_refund_ledger']);
    $this->installConfig(['commerce_payment']);
    $this->container->get('commerce_price.currency_importer')->import('UAH');
  }

  /**
   * Tests payment simulation on a fresh draft cart with various modes.
   */
  #[DataProvider('freshCartProvider')]
  public function testFreshCartSimulation(?string $mode, ?string $capture_amount, bool $full_capture, string $expected_payment_state, string $expected_remote_state, bool $has_partial_adj): void {
    $gateway = PaymentGateway::create([
      'id' => 'novapay_new',
      'label' => 'NovaPay New',
      'plugin' => 'novapay',
    ]);
    $gateway->save();

    $profiles = $this->createMock(RuntimeProfileStorageInterface::class);
    $profiles->method('load')->with($gateway->uuid())->willReturn(
      new RuntimeProfile(
        NovaPayMode::Test,
        'offline-test',
        TransactionMode::Hold,
        'offline-recipient',
        FALSE,
      ),
    );
    $this->container->set('commerce_novapay.runtime_profile_storage', $profiles);

    $item = OrderItem::create([
      'type' => 'test',
      'title' => 'Simulation item',
      'quantity' => '2',
      'unit_price' => new Price('15.00', 'UAH'),
    ]);
    $item->save();

    $order = Order::create([
      'type' => 'default',
      'store_id' => $this->store->id(),
      'state' => 'draft',
      'cart' => TRUE,
      'order_items' => [$item],
    ]);
    $order->save();

    $http = $this->createMock(ClientInterface::class);
    $http->expects(self::never())->method('request');
    $this->container->set('http_client', $http);

    // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- Arguments are consumed by the included Drush script.
    $extra = ['--order-id=' . $order->id()];
    if ($mode !== NULL) {
      $extra[] = '--mode=' . $mode;
    }
    if ($full_capture) {
      $extra[] = '--capture';
    }
    elseif ($capture_amount !== NULL) {
      $extra[] = '--capture-amount=' . $capture_amount;
    }

    $script = dirname(__DIR__, 3) . '/scripts/simulate-payment.php';
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
    self::assertSame($order->id(), $result['order_id']);
    self::assertSame($expected_payment_state, $result['payment_state']);
    self::assertSame($expected_remote_state, $result['remote_state']);
    self::assertSame('novapay_new', $result['gateway']);

    $storage = $this->container->get('entity_type.manager')->getStorage('commerce_payment');
    $storage->resetCache();
    $payments = $storage->loadByProperties(['order_id' => $order->id()]);
    self::assertCount(1, $payments);
    $payment = reset($payments);
    self::assertInstanceOf(PaymentInterface::class, $payment);
    self::assertSame($expected_payment_state, $payment->getState()->getId());
    self::assertSame($expected_remote_state, $payment->getRemoteState());

    $fresh_order = $this->container->get('entity_type.manager')->getStorage('commerce_order')->loadUnchanged($order->id());
    self::assertInstanceOf(Order::class, $fresh_order);
    self::assertNotSame('draft', $fresh_order->getState()->getId());
    self::assertFalse((bool) $fresh_order->get('cart')->value);

    if ($has_partial_adj) {
      self::assertCount(1, $fresh_order->getAdjustments(['novapay_partial_capture']));
      self::assertTrue($fresh_order->getBalance()->isZero());
    }
  }

  /**
   * Data provider for fresh cart simulation scenarios.
   *
   * @return array<string, array{?string, ?string, bool, string, string, bool}>
   *   The scenarios.
   */
  public static function freshCartProvider(): array {
    return [
      'default mode hold' => [NULL, NULL, FALSE, 'authorization', 'holded', FALSE],
      'explicit mode hold' => ['hold', NULL, FALSE, 'authorization', 'holded', FALSE],
      'hold full capture' => ['hold', NULL, TRUE, 'completed', 'hold_confirmed', FALSE],
      'hold partial capture' => ['hold', '20.00', FALSE, 'completed', 'hold_confirmed', TRUE],
      'direct mode' => ['direct', NULL, FALSE, 'completed', 'paid', FALSE],
    ];
  }

  /**
   * Tests simulation on an order that already has a pending payment.
   */
  public function testExistingPendingPayment(): void {
    $gateway = PaymentGateway::create([
      'id' => 'novapay_custom',
      'label' => 'NovaPay Custom',
      'plugin' => 'novapay',
    ]);
    $gateway->save();

    $item = OrderItem::create([
      'type' => 'test',
      'title' => 'Existing order item',
      'quantity' => '1',
      'unit_price' => new Price('50.00', 'UAH'),
    ]);
    $item->save();

    $order = Order::create([
      'type' => 'default',
      'store_id' => $this->store->id(),
      'state' => 'draft',
      'cart' => TRUE,
      'order_items' => [$item],
      'payment_gateway' => $gateway,
    ]);
    $order->save();

    $payment = Payment::create([
      'payment_gateway' => $gateway,
      'order_id' => $order->id(),
      'amount' => new Price('50.00', 'UAH'),
      'state' => 'pending',
      'remote_id' => 'pre-existing-session-123',
      'novapay_operation_id' => 'pre-existing-op-456',
      'novapay_external_id' => (string) $order->id(),
    ]);
    $payment->save();

    $http = $this->createMock(ClientInterface::class);
    $http->expects(self::never())->method('request');
    $this->container->set('http_client', $http);

    // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- Arguments are consumed by the included Drush script.
    $extra = [
      '--order-id=' . $order->id(),
      '--gateway=novapay_custom',
      '--mode=hold',
      '--capture',
      '--existing',
    ];

    $script = dirname(__DIR__, 3) . '/scripts/simulate-payment.php';
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
    self::assertSame($payment->id(), $result['payment_id']);
    self::assertSame('completed', $result['payment_state']);
    self::assertSame('hold_confirmed', $result['remote_state']);
    self::assertTrue($result['existing_payment']);

    $storage = $this->container->get('entity_type.manager')->getStorage('commerce_payment');
    $storage->resetCache();
    $payments = $storage->loadByProperties(['order_id' => $order->id()]);
    self::assertCount(1, $payments);
    $updated_payment = reset($payments);
    self::assertInstanceOf(PaymentInterface::class, $updated_payment);
    self::assertSame('pre-existing-session-123', $updated_payment->getRemoteId());
  }

  /**
   * Tests CLI option validations and error handling.
   *
   * @param list<string> $options
   *   The CLI options.
   * @param class-string<\Throwable> $expected_exception_class
   *   The expected exception class.
   * @param string $expected_message
   *   The expected exception message.
   */
  #[DataProvider('errorScenarioProvider')]
  public function testValidationErrors(array $options, string $expected_exception_class, string $expected_message): void {
    $gateway = PaymentGateway::create([
      'id' => 'novapay_new',
      'label' => 'NovaPay New',
      'plugin' => 'novapay',
    ]);
    $gateway->save();

    $item = OrderItem::create([
      'type' => 'test',
      'title' => 'Test item',
      'quantity' => '1',
      'unit_price' => new Price('25.00', 'UAH'),
    ]);
    $item->save();

    $order = Order::create([
      'type' => 'default',
      'store_id' => $this->store->id(),
      'state' => 'draft',
      'cart' => TRUE,
      'order_items' => [$item],
    ]);
    $order->save();

    // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- Arguments are consumed by the included Drush script.
    $extra = array_map(
      static fn(string $opt): string => str_replace('{order_id}', (string) $order->id(), $opt),
      $options,
    );

    $script = dirname(__DIR__, 3) . '/scripts/simulate-payment.php';
    $this->expectException($expected_exception_class);
    $this->expectExceptionMessage($expected_message);

    ob_start();
    try {
      include $script;
    }
    finally {
      ob_end_clean();
    }
  }

  /**
   * Data provider for error scenarios.
   *
   * @return array<string, array{list<string>, class-string<\Throwable>, string}>
   *   The error scenarios.
   */
  public static function errorScenarioProvider(): array {
    return [
      'missing order id' => [
        [],
        \InvalidArgumentException::class,
        'ORDER_ID must be a positive integer.',
      ],
      'invalid order id format' => [
        ['--order-id=abc'],
        \InvalidArgumentException::class,
        'ORDER_ID must be a positive integer.',
      ],
      'unknown order id' => [
        ['--order-id=999999'],
        \InvalidArgumentException::class,
        'ORDER_ID does not identify an existing order.',
      ],
      'invalid mode' => [
        ['--order-id={order_id}', '--mode=unknown'],
        \InvalidArgumentException::class,
        '--mode must be either "hold" or "direct".',
      ],
      'capture and capture-amount together' => [
        ['--order-id={order_id}', '--mode=hold', '--capture', '--capture-amount=10'],
        \InvalidArgumentException::class,
        'Cannot combine --capture and --capture-amount.',
      ],
      'capture with direct mode' => [
        ['--order-id={order_id}', '--mode=direct', '--capture'],
        \InvalidArgumentException::class,
        '--capture and --capture-amount are only valid when payment mode is hold.',
      ],
      'capture amount exceeding total' => [
        ['--order-id={order_id}', '--mode=hold', '--capture-amount=100.00'],
        \InvalidArgumentException::class,
        'CAPTURE_AMOUNT must not exceed the payment/cart amount.',
      ],
      'invalid capture amount format' => [
        ['--order-id={order_id}', '--mode=hold', '--capture-amount=-5'],
        \InvalidArgumentException::class,
        'CAPTURE_AMOUNT must be a positive UAH amount with at most two decimal places.',
      ],
    ];
  }

}
