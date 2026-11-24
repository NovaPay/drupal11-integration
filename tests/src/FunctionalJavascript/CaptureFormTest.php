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
 * Tests browser constraints and Form API capture POSTs with a fake transport.
 */
#[Group('commerce_novapay')]
final class CaptureFormTest extends CommerceWebDriverTestBase {

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
   * Tests constraints, bypassed POSTs, currency tampering and disabled inputs.
   */
  public function testCapturePostAndBrowserConstraints(): void {
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
    $gateway = PaymentGateway::create([
      'id' => 'novapay_capture_test',
      'label' => 'NovaPay capture test',
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
      'title' => 'Capture test item',
      'quantity' => '1',
      'unit_price' => new Price('200', 'USD'),
    ]);
    $item->save();
    $order = Order::create([
      'type' => 'default',
      'store_id' => $this->store->id(),
      'uid' => $this->adminUser->id(),
      'order_items' => [$item],
    ]);
    $order->save();
    $payment = $this->createHold($gateway, $order, 'partial-session');
    $path = '/admin/commerce/orders/' . $order->id() . '/payments/' . $payment->id() . '/operation/capture';
    $this->drupalGet($path);
    $input = $this->assertSession()->elementExists('css', 'input[name="payment[amount][number]"]');
    foreach (['type' => 'number', 'step' => '0.01', 'min' => '0.01', 'max' => '200.00', 'value' => '200.00'] as $attribute => $expected) {
      self::assertSame($expected, $input->getAttribute($attribute));
    }
    foreach (['-1' => 'rangeUnderflow', '0' => 'rangeUnderflow', '200.01' => 'rangeOverflow', '0.001' => 'stepMismatch'] as $value => $flag) {
      $this->getSession()->executeScript('document.querySelector("input[name=\"payment[amount][number]\"]").value = ' . json_encode((string) $value) . ';');
      self::assertTrue($this->getSession()->evaluateScript('document.querySelector("input[name=\"payment[amount][number]\"]").validity.' . $flag));
      self::assertFalse($this->getSession()->evaluateScript('document.querySelector("input[name=\"payment[amount][number]\"]").checkValidity()'));
    }
    $this->getSession()->executeScript('window.captureSubmitEvents = 0; document.querySelector("input[name=\"payment[amount][number]\"]").form.addEventListener("submit", () => window.captureSubmitEvents++);');
    $this->getSession()->getPage()->pressButton('Capture');
    self::assertSame(0, $this->getSession()->evaluateScript('window.captureSubmitEvents'));
    self::assertSame([], $this->captures());
    foreach (['0.01', '10.25', '200.00'] as $value) {
      $this->getSession()->executeScript('document.querySelector("input[name=\"payment[amount][number]\"]").value = ' . json_encode($value) . ';');
      self::assertTrue($this->getSession()->evaluateScript('document.querySelector("input[name=\"payment[amount][number]\"]").checkValidity()'));
    }
    foreach (['-1', '0', '200.01', '0.001', '1e1'] as $value) {
      $this->drupalGet($path);
      $this->getSession()->executeScript('document.querySelector("input[name=\"payment[amount][number]\"]").form.noValidate = true;');
      $this->submitForm(['payment[amount][number]' => $value], 'Capture');
      $this->assertSession()->pageTextContains($value === '0.001'
        ? 'Amount is not a valid number.'
        : 'The capture amount must be greater than zero and must not exceed the authorized amount.');
      self::assertSame([], $this->captures());
      $this->container->get('entity_type.manager')->getStorage('commerce_payment')->resetCache();
      $current = Payment::load($payment->id());
      self::assertSame('authorization', $current->getState()->getId());
      self::assertTrue($current->get('novapay_pending_operation')->isEmpty());
    }
    $this->drupalGet($path);
    $this->getSession()->executeScript('document.querySelector("input[name=\"payment[amount][currency_code]\"]").value = "EUR";');
    $this->submitForm(['payment[amount][number]' => '10.25'], 'Capture');
    $this->assertSession()->pageTextContains('Capture submitted to NovaPay.');
    self::assertSame('10.25', $this->captures()[0]['operations'][0]['amount']);
    $this->container->get('entity_type.manager')->getStorage('commerce_payment')->resetCache();
    self::assertSame('USD', Payment::load($payment->id())->getAmount()->getCurrencyCode());

    $storage->save($gateway->uuid(), new RuntimeProfile(NovaPayMode::Test, NULL, TransactionMode::Hold, '', FALSE));
    $payment = $this->createHold($gateway, $order, 'full-session');
    $this->drupalGet('/admin/commerce/orders/' . $order->id() . '/payments/' . $payment->id() . '/operation/capture');
    $input = $this->assertSession()->elementExists('css', 'input[name="payment[amount][number]"]');
    self::assertTrue($input->hasAttribute('disabled'));
    self::assertTrue($this->assertSession()->elementExists('css', 'input[name="payment[amount][currency_code]"]')->hasAttribute('disabled'));
    $this->getSession()->executeScript('document.querySelectorAll("input[name^=\"payment[amount]\"]").forEach(input => input.disabled = false); document.querySelector("input[name=\"payment[amount][currency_code]\"]").value = "EUR";');
    $this->submitForm(['payment[amount][number]' => '1.00'], 'Capture');
    $this->assertSession()->pageTextContains('Capture submitted to NovaPay.');
    self::assertCount(2, $this->captures());
    self::assertArrayNotHasKey('operations', $this->captures()[1]);
    self::assertSame('full-session', $this->captures()[1]['session_id']);
    $this->container->get('entity_type.manager')->getStorage('commerce_payment')->resetCache();
    self::assertSame(0, Calculator::compare(Payment::load($payment->id())->get('novapay_pending_amount')->getString(), '200'));
  }

  /**
   * Creates an authorization for a real Commerce operation form.
   */
  private function createHold(PaymentGateway $gateway, Order $order, string $session): Payment {
    $payment = Payment::create([
      'payment_gateway' => $gateway,
      'order_id' => $order->id(),
      'amount' => new Price('200', 'USD'),
      'state' => 'authorization',
      'remote_id' => $session,
      'remote_state' => 'holded',
      'novapay_operation_id' => 'operation-test',
    ]);
    $payment->save();
    return $payment;
  }

  /**
   * Gets payloads recorded by the network-free transport.
   *
   * @return list<array<string, mixed>>
   *   Submitted capture payloads.
   */
  private function captures(): array {
    $state = $this->container->get('state');
    $state->resetCache();
    return $state->get('commerce_novapay_test.captures', []);
  }

}
