<?php

declare(strict_types=1);

namespace Drupal\Tests\commerce_novapay\Functional;

use Drupal\Tests\commerce_order\Functional\OrderBrowserTestBase;
use Drupal\commerce_log\LogStorageInterface;
use Drupal\commerce_log\Entity\LogInterface;
use Drupal\commerce_order\Entity\Order;
use Drupal\commerce_order\Entity\OrderItem;
use Drupal\commerce_payment\Entity\Payment;
use Drupal\commerce_payment\Entity\PaymentGateway;
use Drupal\commerce_price\Price;
use Drupal\user\UserInterface;
use Drupal\state_machine\Plugin\Workflow\WorkflowInterface;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests NovaPay compatibility with optional Commerce order activity.
 */
#[Group('commerce_novapay')]
final class OrderHistoryTest extends OrderBrowserTestBase {

  /**
   * {@inheritdoc}
   *
   * @var list<string>
   */
  protected static $modules = ['commerce_novapay'];

  /**
   * Tests order activity and its access boundary.
   */
  public function testStandardOrderAndPaymentHistory(): void {
    if (!$this->container->get('module_handler')->moduleExists('commerce_log')) {
      $this->markTestSkipped('Commerce Log is not enabled in the test environment.');
    }

    self::assertInstanceOf(UserInterface::class, $this->loggedInUser);
    $item = OrderItem::create([
      'type' => 'default',
      'title' => 'Synthetic history product',
      'quantity' => '1',
      'unit_price' => new Price('30', 'USD'),
    ]);
    $item->save();
    $order = Order::create([
      'type' => 'default',
      'store_id' => $this->store->id(),
      'uid' => $this->loggedInUser->id(),
      'mail' => $this->loggedInUser->getEmail(),
      'order_items' => [$item],
    ]);
    $order->save();
    $order = $this->reloadEntity($order);
    self::assertInstanceOf(Order::class, $order);
    $workflow = $order->getState()->getWorkflow();
    self::assertInstanceOf(WorkflowInterface::class, $workflow);
    $transition = $workflow->getTransition('place');
    $order->getState()->applyTransitionById('place');
    $order->save();

    $gateway = PaymentGateway::create([
      'id' => 'novapay_history',
      'label' => 'NovaPay history',
      'plugin' => 'novapay',
    ]);
    $gateway->save();
    $payment = Payment::create([
      'payment_gateway' => $gateway,
      'order_id' => $order->id(),
      'amount' => new Price('30', 'USD'),
      'state' => 'pending',
      'test' => TRUE,
    ]);
    $payment->save();
    $payment->setState('completed');
    $payment->save();

    $storage = $this->container->get('entity_type.manager')->getStorage('commerce_log');
    self::assertInstanceOf(LogStorageInterface::class, $storage);
    $logs = $storage->loadMultipleByEntity($order);
    $templates = [];
    foreach ($logs as $log) {
      $templates[] = $log->getTemplateId();
    }
    self::assertContains('order_state_updated', $templates);
    self::assertContains('payment_added', $templates);
    self::assertContains('payment_completed', $templates);

    $this->drupalGet($order->toUrl('canonical'));
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Order activity');
    $this->assertSession()->pageTextContains($transition->getLabel() . ' transition.');
    $this->assertSession()->pageTextContains('Payment added via NovaPay');
    $this->assertSession()->pageTextContains('Payment captured via NovaPay');

    // Cancellation proves accurate source and destination state history.
    $canceled = Order::create([
      'type' => 'default',
      'store_id' => $this->store->id(),
      'uid' => $this->loggedInUser->id(),
      'mail' => $this->loggedInUser->getEmail(),
    ]);
    $canceled->save();
    $canceled = $this->reloadEntity($canceled);
    self::assertInstanceOf(Order::class, $canceled);
    $canceled->getState()->applyTransitionById('cancel');
    $canceled->save();
    $cancel_logs = $storage->loadByProperties([
      'template_id' => 'order_state_updated',
      'source_entity_type' => 'commerce_order',
      'source_entity_id' => $canceled->id(),
    ]);
    self::assertCount(1, $cancel_logs);
    $cancel_log = reset($cancel_logs);
    self::assertInstanceOf(LogInterface::class, $cancel_log);
    $params = $cancel_log->getParams();
    self::assertSame('Draft', $params['from_state']);
    self::assertSame('Canceled', $params['to_state']);
    $this->drupalGet($canceled->toUrl('canonical'));
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Order activity');
    $this->assertSession()->pageTextContains('Order moved from Draft to Canceled by the Cancel order transition.');

    $this->drupalLogin($this->drupalCreateUser(['access administration pages']));
    $this->drupalGet($order->toUrl('canonical'));
    $this->assertSession()->statusCodeEquals(403);
    $this->assertSession()->pageTextNotContains('Order moved from');
    $this->assertSession()->pageTextNotContains('Payment captured via NovaPay');
  }

}
