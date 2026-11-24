<?php

declare(strict_types=1);

namespace Drupal\Tests\commerce_novapay\Functional;

use Drupal\commerce_novapay\Runtime\TransactionMode;
use Drupal\commerce_novapay\Runtime\RuntimeProfile;
use Drupal\commerce_novapay\Credential\NovaPayMode;
use Drupal\Tests\commerce_order\Functional\OrderBrowserTestBase;
use Drupal\commerce_order\Adjustment;
use Drupal\commerce_order\Entity\Order;
use Drupal\commerce_order\Entity\OrderItem;
use Drupal\commerce_novapay\Payment\RefundSelection;
use Drupal\commerce_payment\Entity\Payment;
use Drupal\commerce_payment\Entity\PaymentGateway;
use Drupal\commerce_price\Price;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\locale\Gettext;
use Drupal\views\Entity\View;
use Drupal\user\UserInterface;
use Drupal\state_machine\Plugin\Workflow\WorkflowInterface;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests payment-state labels without a NovaPay-specific order Views field.
 */
#[Group('commerce_novapay')]
final class OrderPaymentStatusTest extends OrderBrowserTestBase {

  /**
   * {@inheritdoc}
   *
   * @var list<string>
   */
  protected static $modules = ['commerce_novapay', 'locale'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->container->get('commerce_price.currency_importer')->import('UAH');
  }

  /**
   * Tests payment labels while obsolete order status output remains absent.
   */
  public function testOrderStatus(): void {
    self::assertInstanceOf(UserInterface::class, $this->loggedInUser);
    $gateway = PaymentGateway::create([
      'id' => 'novapay_display',
      'label' => 'NovaPay',
      'plugin' => 'novapay',
    ]);
    $gateway->save();
    $order = Order::create([
      'type' => 'default',
      'store_id' => $this->store->id(),
      'uid' => $this->loggedInUser->id(),
      'mail' => $this->loggedInUser->getEmail(),
    ]);
    $order->save();
    $display = $this->container->get('commerce_novapay.payment_status_display');
    self::assertArrayNotHasKey('novapay_payment_status', $this->container->get('views.views_data')->get('commerce_order'));
    $this->drupalGet($order->toUrl('canonical'));
    $this->assertSession()->pageTextNotContains('NovaPay payment status');
    $payment = Payment::create([
      'payment_gateway' => $gateway,
      'order_id' => $order->id(),
      'amount' => new Price('30', 'USD'),
      'state' => 'pending',
      'remote_state' => 'created',
    ]);
    $payment->save();
    foreach ([
      ['pending', 'created', 'Processing'],
      ['pending', 'processing', 'Pending payment'],
      ['authorization', 'holded', 'On hold'],
      ['completed', 'hold_confirmed', 'Completed'],
      ['completed', 'paid', 'Paid'],
      ['partially_refunded', 'paid', 'Refunded'],
      ['partially_refunded', 'hold_confirmed', 'Refunded'],
      ['refunded', 'voided', 'Refunded'],
      ['authorization_voided', 'voided', 'Refunded'],
      ['failed', 'failed', 'Failed'],
      ['expired', 'expired', 'Expired'],
    ] as [$state, $remote, $label]) {
      $payment->setState($state);
      $payment->setRemoteState($remote);
      $payment->save();
      self::assertSame($label, $display->label($payment));
      $this->drupalGet($order->toUrl('canonical'));
      $this->assertSession()->statusCodeEquals(200);
      $this->assertSession()->pageTextNotContains('NovaPay payment status');
      $this->drupalGet('/admin/commerce/orders');
      $this->assertSession()->statusCodeEquals(200);
      $this->assertSession()->pageTextNotContains('NovaPay payment status');
    }
    ConfigurableLanguage::createFromLangcode('uk')->save();
    $path = $this->container->get('extension.list.module')->getPath('commerce_novapay');
    Gettext::fileToDatabase((object) [
      'langcode' => 'uk',
      'uri' => $path . '/translations/commerce_novapay.uk.po',
    ], []);
    $this->container->get('string_translation')->reset();
    $this->container->get('string_translation')->setDefaultLangcode('uk');
    foreach ([
      ['pending', 'created', 'В обробці'],
      ['pending', 'processing', 'Очікування оплати'],
      ['authorization', 'holded', 'На утриманні'],
      ['completed', 'paid', 'Виконано'],
      ['completed', 'hold_confirmed', 'Виконано'],
      ['partially_refunded', 'paid', 'Повернено'],
      ['refunded', 'voided', 'Повернено'],
      ['authorization_voided', 'voided', 'Повернено'],
      ['failed', 'failed', 'Не вдалося'],
      ['expired', 'expired', 'Прострочено'],
    ] as [$state, $remote, $label]) {
      $payment->setState($state);
      $payment->setRemoteState($remote);
      self::assertSame($label, $display->label($payment));
    }
    // Shared State Machine labels remain unchanged for other providers.
    // NovaPay screens use PaymentStatusDisplay with its own context.
    $workflow = $this->container->get('plugin.manager.workflow')->createInstance('novapay_payment');
    self::assertInstanceOf(WorkflowInterface::class, $workflow);
    foreach ([
      'pending' => 'Очікує оплати',
      'authorization' => 'Кошти заблоковано',
      'completed' => 'Завершено',
      'partially_refunded' => 'Частково повернено',
      'refunded' => 'Повернено',
      'authorization_voided' => 'Повернено',
      'expired' => 'Термін дії минув',
      'failed' => 'Невдалий платіж',
    ] as $id => $label) {
      self::assertSame($label, $workflow->getState($id)->getLabel());
    }
    foreach ([
      'authorize' => 'Заблокувати кошти',
      'authorize_capture' => 'Авторизувати та списати кошти',
      'capture' => 'Підтвердити холдування',
      'partially_refund' => 'Частково повернути кошти',
      'refund' => 'Повернути кошти',
      'void' => 'Скасувати блокування коштів',
      'expire' => 'Завершити термін дії платежу',
      'fail' => 'Позначити платіж як невдалий',
      'retry' => 'Повторити оплату',
      'retry_authorize' => 'Заблокувати кошти повторного платежу',
      'retry_authorize_capture' => 'Авторизувати та списати кошти повторного платежу',
    ] as $id => $label) {
      self::assertSame($label, $workflow->getTransition($id)->getLabel());
    }
    $this->container->get('string_translation')->setDefaultLangcode('en');
    $this->drupalLogin($this->drupalCreateUser(['access administration pages']));
    $this->drupalGet($order->toUrl('canonical'));
    $this->assertSession()->statusCodeEquals(403);
    $this->assertSession()->pageTextNotContains('NovaPay payment status');
  }

  /**
   * Tests old English adjustment labels follow the interface language.
   */
  public function testAdjustmentLabelsFollowInterfaceLanguage(): void {
    ConfigurableLanguage::createFromLangcode('uk')->save();
    $this->config('language.types')->set('negotiation.language_interface.enabled', [
      'language-url' => 0, 'language-selected' => 1,
    ])->save();
    $this->config('language.negotiation')->set('url.prefixes', ['en' => '', 'uk' => 'uk'])->save();
    $path = $this->container->get('extension.list.module')->getPath('commerce_novapay');
    Gettext::fileToDatabase((object) [
      'langcode' => 'uk', 'uri' => $path . '/translations/commerce_novapay.uk.po',
    ], []);
    $item = OrderItem::create([
      'type' => 'default', 'purchased_entity' => $this->variation,
      'quantity' => '1',
    ]);
    $item->setUnitPrice(new Price('40', 'USD'), TRUE);
    $item->save();
    $order = Order::create([
      'type' => 'default', 'store_id' => $this->store->id(),
      'uid' => $this->adminUser->id(), 'state' => 'completed',
      'langcode' => 'und', 'order_items' => [$item],
    ]);
    foreach ([
      ['novapay_partial_refund', 'NovaPay partial refund adjustment', '-5'],
      ['novapay_partial_capture', 'NovaPay partial capture adjustment', '-2'],
      ['custom', 'External discount', '-1'],
    ] as [$type, $label, $amount]) {
      $order->addAdjustment(new Adjustment([
        'type' => $type, 'label' => $label, 'amount' => new Price($amount, 'USD'),
        'locked' => TRUE, 'source_id' => 'test-' . $type,
      ]));
    }
    $order->save();
    $route = 'admin/commerce/orders/' . $order->id();
    foreach (['uk/' . $route, $route, 'uk/' . $route] as $page) {
      $ukrainian = str_starts_with($page, 'uk/');
      $this->drupalGet($page);
      $this->assertSession()->statusCodeEquals(200);
      $this->assertSession()->pageTextContains($ukrainian
        ? 'Коригування після часткового повернення NovaPay'
        : 'NovaPay partial refund adjustment');
      $this->assertSession()->pageTextContains($ukrainian
        ? 'Коригування після часткового підтвердження холду NovaPay'
        : 'NovaPay partial capture adjustment');
      if ($ukrainian) {
        $this->assertSession()->pageTextNotContains('NovaPay partial refund adjustment');
        $this->assertSession()->pageTextNotContains('NovaPay partial capture adjustment');
      }
      $this->assertSession()->pageTextContains('External discount');
    }
    $stored = $this->container->get('entity_type.manager')->getStorage('commerce_order')->loadUnchanged($order->id());
    self::assertInstanceOf(Order::class, $stored);
    self::assertSame('und', $stored->language()->getId());
    self::assertEquals(new Price('32', 'USD'), $stored->getTotalPrice());
    self::assertSame('NovaPay partial refund adjustment', $stored->getAdjustments(['novapay_partial_refund'])[0]->getLabel());
  }

  /**
   * Tests translated hold controls without submitting a financial operation.
   */
  public function testHoldCancellationLabels(): void {
    $this->drupalLogin($this->drupalCreateUser([
      'administer commerce_payment',
      'administer commerce_order',
      'access commerce administration pages',
      'access administration pages',
    ]));
    self::assertInstanceOf(UserInterface::class, $this->loggedInUser);
    $language = ConfigurableLanguage::createFromLangcode('uk');
    $language->save();
    $this->config('language.types')->set('negotiation.language_interface.enabled', [
      'language-url' => 0,
      'language-selected' => 1,
    ])->save();
    $path = $this->container->get('extension.list.module')->getPath('commerce_novapay');
    Gettext::fileToDatabase((object) [
      'langcode' => 'uk',
      'uri' => $path . '/translations/commerce_novapay.uk.po',
    ], []);
    $this->writeSettings([
      'settings' => [
        'file_private_path' => (object) [
          'value' => $this->siteDirectory . '/private',
          'required' => TRUE,
        ],
      ],
    ]);
    $this->rebuildContainer();
    $gateway = PaymentGateway::create([
      'id' => 'novapay_hold_labels',
      'label' => 'NovaPay',
      'plugin' => 'novapay',
    ]);
    $gateway->save();
    $this->container->get('commerce_novapay.runtime_profile_storage')->save(
      $gateway->uuid(),
      new RuntimeProfile(
        NovaPayMode::Test,
        NULL,
        TransactionMode::Hold,
        '',
        FALSE,
      ),
    );
    $order = Order::create([
      'type' => 'default',
      'store_id' => $this->store->id(),
      'uid' => $this->loggedInUser->id(),
      'mail' => $this->loggedInUser->getEmail(),
    ]);
    $order->save();
    $payment = Payment::create([
      'payment_gateway' => $gateway,
      'order_id' => $order->id(),
      'amount' => new Price('30', 'UAH'),
      'state' => 'authorization',
      'remote_state' => 'holded',
    ]);
    $payment->save();
    $base = 'uk/admin/commerce/orders/' . $order->id() . '/payments';
    $this->drupalGet($base);
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->linkExists('Підтвердити холдування');
    $this->assertSession()->linkNotExists('Захопити');
    $this->clickLink('Підтвердити холдування');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->elementTextContains('css', 'h1', 'Підтвердити холдування');
    $this->assertSession()->buttonExists('Підтвердити холдування');
    $this->assertSession()->pageTextNotContains('Списати кошти');
    $this->drupalGet($base);
    $this->assertSession()->linkExists('Повернення');
    $this->assertSession()->linkNotExists('Скасування холду');
    $this->assertSession()->pageTextNotContains('Порожньо');
    $this->clickLink('Повернення');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->buttonExists('Повернути через NovaPay');
    $this->assertSession()->pageTextContains('Ви впевнені, що хочете скасувати холд?');
    $this->assertSession()->pageTextContains('Цю дію неможливо скасувати.');
    $this->assertSession()->linkExists('Назад');
    $this->assertSession()->pageTextNotContains('This action cannot be undone.');
    $this->drupalGet('admin/commerce/orders/' . $order->id() . '/payments/' . $payment->id() . '/operation/void');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->buttonExists('Refund via NovaPay');
    $this->container->get('entity_type.manager')->getStorage('commerce_payment')->resetCache([$payment->id()]);
    $unchanged = Payment::load($payment->id());
    self::assertNotNull($unchanged);
    self::assertSame('authorization', $unchanged->getState()->getId());
    self::assertTrue($unchanged->get('novapay_pending_operation')->isEmpty());
    $payment->setState('completed');
    $payment->setRemoteState('paid');
    $payment->save();
    $this->drupalGet($base);
    $this->assertSession()->linkExists('Повернення');
    $this->clickLink('Повернення');
    $this->assertSession()->buttonExists('Повернути через NovaPay');
    $this->assertSession()->pageTextContains('Загальна сума до повернення');
    $this->assertSession()->pageTextContains('Часткове повернення можливе');
    $this->assertSession()->elementNotExists('css', '.commerce-novapay-refund-items th:nth-child(7)');

    // Partial refund keeps the "Повернення" link active while paid.
    $payment->setState('partially_refunded');
    $payment->setRemoteState('paid');
    $payment->setRefundedAmount(new Price('10', 'UAH'));
    $payment->save();
    $this->drupalGet($base);
    $this->assertSession()->linkExists('Повернення');

    // A voided postback even on a partial balance deactivates the link.
    $payment->setRemoteState('voided');
    $payment->save();
    $this->drupalGet($base);
    $this->assertSession()->linkNotExists('Повернення');
    $this->drupalGet('admin/commerce/orders/' . $order->id() . '/payments/' . $payment->id() . '/operation/refund');
    $this->assertSession()->statusCodeEquals(403);

    // Voided postback for a full refund deactivates the "Повернення" link.
    $payment->setState('refunded');
    $payment->setRemoteState('voided');
    $payment->setRefundedAmount(new Price('30', 'UAH'));
    $payment->save();
    $this->drupalGet($base);
    $this->assertSession()->linkNotExists('Повернення');
    $this->assertSession()->linkNotExists('Підтвердити холдування');
    $this->drupalGet('admin/commerce/orders/' . $order->id() . '/payments/' . $payment->id() . '/operation/refund');
    $this->assertSession()->statusCodeEquals(403);

    // Voided postback for an authorization deactivates the "Повернення" link.
    $payment->setState('authorization_voided');
    $payment->setRemoteState('voided');
    $payment->save();
    $this->drupalGet($base);
    $this->assertSession()->linkNotExists('Повернення');
    $this->assertSession()->linkNotExists('Підтвердити холдування');
    $this->drupalGet('admin/commerce/orders/' . $order->id() . '/payments/' . $payment->id() . '/operation/void');
    $this->assertSession()->statusCodeEquals(403);

    // Returned items display their confirmed refund amount beside the position.
    $order_item = OrderItem::create([
      'type' => 'default',
      'title' => 'Тестовий товар',
      'quantity' => '1',
      'unit_price' => new Price('30', 'UAH'),
    ]);
    $order_item->save();
    $order->addItem($order_item);
    $order->save();
    $this->container->get('commerce_novapay.refund_ledger')->recordConfirmed(
      (int) $payment->id(),
      'test-order-item-event',
      [new RefundSelection((int) $order_item->id(), '1', new Price('30', 'UAH'))],
    );
    $this->drupalGet('uk/admin/commerce/orders/' . $order->id());
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Тестовий товар');
    $this->assertSession()->pageTextNotContains('Повернено:');
    $items_view = View::load('commerce_order_item_table_admin');
    self::assertNotNull($items_view);
    $displays = $items_view->get('display');
    $displays['default']['display_options']['fields']['novapay_refunded_amount'] = [
      'id' => 'novapay_refunded_amount',
      'table' => 'commerce_order_item',
      'field' => 'novapay_refunded_amount',
      'plugin_id' => 'commerce_novapay_order_item_refunded_amount',
      'label' => 'Confirmed item refund',
    ];
    $items_view->set('display', $displays)->save();
    $this->drupalGet('uk/admin/commerce/orders/' . $order->id());
    $this->assertSession()->pageTextContains('Confirmed item refund');
    $expected = $this->container->get('commerce_price.currency_formatter')->format('30', 'UAH');
    $this->assertSession()->elementTextContains('css', '.views-field-novapay-refunded-amount', $expected);
    $status_order = Order::create([
      'type' => 'default',
      'store_id' => $this->store->id(),
      'uid' => $this->loggedInUser->id(),
      'mail' => $this->loggedInUser->getEmail(),
    ]);
    $status_order->save();
    $labels = [
      ['completed', 'paid', 'Paid', 'Виконано'],
      ['completed', 'hold_confirmed', 'Completed', 'Виконано'],
      ['partially_refunded', 'paid', 'Refunded', 'Повернено'],
      ['authorization_voided', 'voided', 'Refunded', 'Повернено'],
    ];
    foreach ($labels as [$state, $remote]) {
      Payment::create([
        'payment_gateway' => $gateway,
        'order_id' => $status_order->id(),
        'amount' => new Price('30', 'UAH'),
        'state' => $state,
        'remote_state' => $remote,
      ])->save();
    }
    $manual_gateway = PaymentGateway::create([
      'id' => 'manual_status_labels',
      'label' => 'Manual',
      'plugin' => 'manual',
    ]);
    $manual_gateway->save();
    Payment::create([
      'payment_gateway' => $manual_gateway,
      'order_id' => $status_order->id(),
      'amount' => new Price('30', 'UAH'),
      'state' => 'completed',
    ])->save();
    $labels[] = ['completed', '', 'Completed', 'Завершено'];
    $payment_view = View::load('commerce_order_payments');
    self::assertNotNull($payment_view);
    $view_configuration = $payment_view->toArray();
    $status_base = 'admin/commerce/orders/' . $status_order->id() . '/payments';
    foreach (['en', 'uk'] as $langcode) {
      $this->drupalGet(($langcode === 'uk' ? 'uk/' : '') . $status_base);
      $this->assertSession()->statusCodeEquals(200);
      foreach (array_reverse($labels) as $index => [, , $english, $ukrainian]) {
        $selector = 'table tbody tr:nth-child(' . ($index + 1) . ') td:nth-child(2)';
        $cell = $this->getSession()->getPage()->find('css', $selector);
        self::assertNotNull($cell);
        self::assertSame($langcode === 'uk' ? $ukrainian : $english, $cell->getText());
      }
    }
    self::assertSame($view_configuration, View::load('commerce_order_payments')?->toArray());
  }

}
