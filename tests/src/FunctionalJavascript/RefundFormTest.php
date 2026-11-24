<?php

declare(strict_types=1);

namespace Drupal\Tests\commerce_novapay\FunctionalJavascript;

use Drupal\Tests\commerce\FunctionalJavascript\CommerceWebDriverTestBase;
use Drupal\commerce_novapay\Credential\NovaPayMode;
use Drupal\commerce_novapay\Runtime\RuntimeProfile;
use Drupal\commerce_novapay\Runtime\TransactionMode;
use Drupal\commerce_order\Entity\Order;
use Drupal\commerce_order\Entity\OrderItem;
use Drupal\commerce_order\Entity\OrderItemType;
use Drupal\commerce_payment\Entity\Payment;
use Drupal\commerce_payment\Entity\PaymentGateway;
use Drupal\commerce_price\Calculator;
use Drupal\commerce_price\Price;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the actual Commerce refund form with a network-free transport.
 */
#[Group('commerce_novapay')]
final class RefundFormTest extends CommerceWebDriverTestBase {

  /**
   * {@inheritdoc}
   *
   * @var list<string>
   */
  protected static $modules = ['commerce_novapay_test'];

  /**
   * {@inheritdoc}
   *
   * @return string[]
   *   Payment administrator permissions.
   */
  protected function getAdministratorPermissions(): array {
    return array_merge(parent::getAdministratorPermissions(), [
      'administer commerce_order',
      'administer commerce_payment',
    ]);
  }

  /**
   * Tests item-only quantities, full intent and server validation.
   */
  public function testItemRefundsAndServerValidation(): void {
    $private = $this->siteDirectory . '/private';
    if (!is_dir($private)) {
      self::assertTrue(mkdir($private, 0700, TRUE));
    }
    $this->writeSettings([
      'settings' => [
        'file_private_path' => (object) ['value' => $private, 'required' => TRUE],
      ],
    ]);
    $this->rebuildContainer();
    $this->container->get('commerce_price.currency_importer')->import('UAH');
    $gateway = PaymentGateway::create([
      'id' => 'novapay_refund_test',
      'label' => 'NovaPay refund test',
      'plugin' => 'novapay',
    ]);
    $gateway->save();
    $storage = $this->container->get('commerce_novapay.runtime_profile_storage');
    $storage->save($gateway->uuid(), new RuntimeProfile(NovaPayMode::Test, NULL, TransactionMode::Hold, 'recipient', FALSE));
    OrderItemType::create([
      'id' => 'test',
      'label' => 'Test',
      'orderType' => 'default',
    ])->save();
    $item = OrderItem::create([
      'type' => 'test',
      'title' => 'Synthetic refund item',
      'quantity' => '1',
      'unit_price' => new Price('1000', 'UAH'),
    ]);
    $item->save();
    $order = Order::create([
      'type' => 'default',
      'store_id' => $this->store->id(),
      'uid' => $this->adminUser->id(),
      'order_items' => [$item],
    ]);
    $order->save();
    $payment = Payment::create([
      'payment_gateway' => $gateway,
      'order_id' => $order->id(),
      'amount' => new Price('600', 'UAH'),
      'state' => 'completed',
      'remote_id' => 'synthetic-refund-session',
      'remote_state' => 'paid',
      'novapay_operation_id' => 'synthetic-operation',
    ]);
    $payment->save();
    $path = '/admin/commerce/orders/' . $order->id() . '/payments/' . $payment->id() . '/operation/refund';
    $this->drupalGet($path);
    $page = $this->getSession()->getPage();
    $this->assertSession()->pageTextContains('600');
    $this->assertSession()->pageTextNotContains('Refund mode');
    $this->assertSession()->pageTextNotContains('By items');
    $this->assertSession()->elementExists('css', '.commerce-novapay-refund-notice[role="note"]');
    $this->assertSession()->pageTextContains('Important refund information');
    $this->assertSession()->elementNotExists('css', '.commerce-novapay-refund-amount');
    $this->assertSession()->elementNotExists('css', 'input[value="amount"]');
    $this->assertJsCondition("document.querySelector('.commerce-novapay-refund-total').textContent === '600 UAH'");
    $quantity = $page->find('css', '.commerce-novapay-refund-quantity');
    self::assertNotNull($quantity);
    self::assertSame('0', $quantity->getAttribute('min'));
    self::assertSame('1', $quantity->getAttribute('max'));
    $quantity->setValue('1');
    $this->captureEvidence('refund-form-full-items.png');
    $this->assertJsCondition("document.querySelector('.commerce-novapay-refund-total').textContent === '600 UAH'");
    // Exercise server validation while bypassing browser constraints.
    foreach (['-1', '2', '0.5', '1e0', 'NaN'] as $invalid) {
      $this->drupalGet($path);
      $this->getSession()->executeScript("const q = document.querySelector('.commerce-novapay-refund-quantity'); q.type = 'text'; q.form.noValidate = true; q.value = " . json_encode($invalid) . ';');
      $this->getSession()->getPage()->pressButton('Refund via NovaPay');
      self::assertSame([], $this->refunds());
      $this->assertSession()->elementExists('css', '[role="alert"]');
    }
    // Blank, zero and all purchased quantities each request a full refund.
    foreach (['', '0', '1'] as $index => $value) {
      $next = $payment->createDuplicate();
      $next->setRemoteId('synthetic-full-refund-' . $index);
      $next->save();
      $this->drupalGet('/admin/commerce/orders/' . $order->id() . '/payments/' . $next->id() . '/operation/refund');
      $this->getSession()->getPage()->find('css', '.commerce-novapay-refund-quantity')->setValue($value);
      $this->getSession()->getPage()->pressButton('Refund via NovaPay');
      self::assertStringContainsString('Refund submitted to NovaPay.', $this->getSession()->getPage()->getText(), 'Full selection: ' . var_export($value, TRUE));
      $requests = $this->refunds();
      self::assertCount($index + 1, $requests);
      self::assertArrayNotHasKey('operations', $requests[$index]);
      $this->container->get('entity_type.manager')->getStorage('commerce_payment')->resetCache();
      $current = Payment::load($next->id());
      self::assertNotNull($current);
      self::assertSame('completed', $current->getState()->getId());
      self::assertSame(0, Calculator::compare($current->getRefundedAmount()->getNumber(), '0'));
      self::assertSame('600', $current->get('novapay_pending_amount')->getString());
    }

    // Partial hold capture keeps item inputs and blank-selection full intent.
    $captured = $payment->createDuplicate();
    $captured->setRemoteId('synthetic-partial-capture-refund');
    $captured->setAuthorizedTime(time());
    $captured->save();
    $fresh_order = $this->container->get('entity_type.manager')->getStorage('commerce_order')->loadUnchanged($order->id());
    self::assertInstanceOf(Order::class, $fresh_order);
    $fresh_order->setData('commerce_novapay_partial_captures', [(string) $captured->id() => TRUE])->save();
    $this->drupalGet('/admin/commerce/orders/' . $order->id() . '/payments/' . $captured->id() . '/operation/refund');
    $this->assertSession()->elementExists('css', '.commerce-novapay-refund-quantity');
    $this->assertSession()->pageTextNotContains('Only a full refund of the remaining paid balance is available');
    $this->captureEvidence('refund-partial-capture-items.png');
    $this->getSession()->getPage()->pressButton('Refund via NovaPay');
    self::assertStringContainsString('Refund submitted to NovaPay.', $this->getSession()->getPage()->getText());
    $requests = $this->refunds();
    self::assertCount(4, $requests);
    self::assertArrayNotHasKey('operations', $requests[3]);

    // Commerce requires an authorized administrator for refund operations.
    $this->drupalLogout();
    $this->drupalGet($path);
    $this->assertSession()->pageTextContains('Access denied');

  }

  /**
   * Captures optional screenshots of the actual synthetic Commerce refund form.
   */
  private function captureEvidence(string $filename): void {
    $directory = getenv('NOVAPAY_SCREENSHOT_DIR');
    if (is_string($directory) && $directory !== '' && is_dir($directory)) {
      $this->getSession()->resizeWindow(1100, 900);
      $this->createScreenshot($directory . '/' . $filename);
    }
  }

  /**
   * Gets recorded network-free refund payloads.
   *
   * @return list<array<string, mixed>>
   *   Submitted refund payloads.
   */
  private function refunds(): array {
    $state = $this->container->get('state');
    $state->resetCache();
    return $state->get('commerce_novapay_test.refunds', []);
  }

}
