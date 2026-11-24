<?php

declare(strict_types=1);

namespace Drupal\Tests\commerce_novapay\Kernel;

use Drupal\commerce_price\Calculator;
use Drupal\commerce_novapay\Payment\OrderItemRefundAmountDisplay;
use Drupal\Core\Entity\Entity\EntityViewDisplay;
use Drupal\Core\Entity\EntityViewBuilder;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\views\Entity\View;
use Drupal\Core\Render\RenderContext;
use Drupal\commerce_order\Adjustment;
use PHPUnit\Framework\Attributes\DataProvider;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\Entity\EntityFormInterface;
use Drupal\Core\Entity\Entity\EntityFormDisplay;
use Drupal\Core\Form\FormState;
use Drupal\Core\Routing\RouteMatch;
use Drupal\commerce_payment\Access\PaymentOperationAccessCheck;
use Drupal\Core\Field\FormatterInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Tests\commerce_order\Kernel\OrderKernelTestBase;
use Drupal\commerce_novapay\Hook\FieldConfigFormHooks;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Route;
use Symfony\Component\HttpFoundation\ParameterBag;
use Drupal\commerce_novapay\Api\Dto\Request\CompleteHoldRequest;
use Drupal\commerce_novapay\Api\Dto\Request\VoidRequest;
use Drupal\commerce_novapay\Api\Dto\Response\AcknowledgementResponse;
use Drupal\commerce_novapay\Api\Dto\Response\SessionStatusResponse;
use Drupal\commerce_novapay\Api\NovaPayApiClientInterface;
use Drupal\commerce_novapay\Credential\CredentialResolverInterface;
use Drupal\commerce_novapay\Credential\Credentials;
use Drupal\commerce_novapay\Credential\NovaPayMode;
use Drupal\commerce_novapay\Exception\ApiFatalException;
use Drupal\commerce_novapay\Exception\ApiProcessingException;
use Drupal\commerce_novapay\Exception\ApiTransportException;
use Drupal\commerce_novapay\Exception\ApiUnexpectedResponseException;
use Drupal\commerce_novapay\Exception\ApiValidationException;
use Drupal\commerce_novapay\Payment\RefundLedgerRepository;
use Drupal\commerce_novapay\Payment\PaymentOrderLock;
use Drupal\commerce_novapay\Payment\RefundableItem;
use Drupal\commerce_novapay\Payment\RefundSelection;
use Drupal\commerce_novapay\Plugin\Commerce\PaymentGateway\NovaPay;
use Drupal\commerce_novapay\Plugin\Commerce\PaymentGateway\NovaPayConfigurationGatewayBase;
use Drupal\commerce_novapay\Payment\AuthorizationOperationManager;
use Drupal\commerce_novapay\Payment\PaymentStatusCheckManager;
use Drupal\commerce_novapay\Payment\PaymentStatusCheckResult;
use Drupal\commerce_novapay\Payment\RefundOperationManager;
use Drupal\commerce_novapay\Payment\RefundStatusCheckResult;
use Drupal\commerce_novapay\Payment\SessionLockName;
use Drupal\commerce_novapay\Phone\CustomerProfilePhoneInspector;
use Drupal\commerce_novapay\Phone\CustomerProfilePhoneInspectorInterface;
use Drupal\commerce_novapay\Plugin\Commerce\PaymentType\NovaPayPayment;
use Drupal\commerce_novapay\PluginForm\NovaPayCaptureForm;
use Drupal\Core\Render\Element\Number as NumberElement;
use Drupal\commerce_novapay\Postback\NovaPayStatus;
use Drupal\commerce_novapay\Postback\Dto\NormalizedPostbackEvent;
use Drupal\commerce_novapay\Postback\PostbackEventRepository;
use Drupal\commerce_novapay\Postback\PostbackEventRepositoryInterface;
use Drupal\commerce_novapay\Postback\PostbackOutcome;
use Drupal\commerce_novapay\Postback\PostbackProcessor;
use Drupal\commerce_novapay\Signature\VerifierInterface;
use Drupal\commerce_novapay\Signature\SandboxLegacyVerifierInterface;
use Drupal\commerce_novapay\Postback\PaymentStatusMapper;
use Drupal\commerce_novapay\Postback\PaymentStatusMapperInterface;
use Drupal\commerce_novapay\Phone\OrderPhoneResolverInterface;
use Drupal\commerce_novapay\PluginForm\NovaPayPaymentOffsiteForm;
use Drupal\commerce_novapay\PluginForm\NovaPayPaymentStatusForm;
use Drupal\commerce_novapay\PluginForm\NovaPayRefundStatusForm;
use Drupal\commerce_novapay\PluginForm\NovaPayVoidForm;
use Drupal\commerce_novapay\PluginForm\NovaPayRefundForm;
use Drupal\commerce_novapay\Runtime\RuntimeConfiguration;
use Drupal\commerce_novapay\Runtime\RuntimeConfigurationProviderInterface;
use Drupal\commerce_novapay\Runtime\RuntimeProfile;
use Drupal\commerce_novapay\Runtime\TransactionMode;
use Drupal\commerce_order\Entity\Order;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_order\Entity\OrderItem;
use Drupal\commerce_payment\Entity\PaymentGateway;
use Drupal\commerce_payment\Entity\PaymentInterface;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\OffsitePaymentGatewayInterface;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\SupportsAuthorizationsInterface;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\SupportsRefundsInterface;
use Drupal\commerce_payment\Exception\InvalidRequestException;
use Drupal\commerce_payment\Exception\PaymentGatewayException;
use Drupal\commerce_price\Price;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\profile\Entity\ProfileType;
use Drupal\state_machine\WorkflowManagerInterface;
use Drupal\state_machine\Plugin\Workflow\WorkflowInterface;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the NovaPay payment type, workflow, fields, and order integration.
 */
#[CoversClass(NovaPayPayment::class)]
#[CoversClass(CustomerProfilePhoneInspector::class)]
#[CoversClass(PaymentStatusMapper::class)]
#[CoversClass(PostbackEventRepository::class)]
#[CoversClass(AuthorizationOperationManager::class)]
#[CoversClass(RefundOperationManager::class)]
#[CoversClass(RefundLedgerRepository::class)]
#[CoversClass(RefundableItem::class)]
#[CoversClass(RefundSelection::class)]
#[CoversClass(PaymentStatusCheckManager::class)]
#[CoversClass(NovaPay::class)]
#[CoversClass(NovaPayConfigurationGatewayBase::class)]
#[CoversClass(NovaPayCaptureForm::class)]
#[CoversClass(NovaPayVoidForm::class)]
#[CoversClass(NovaPayRefundForm::class)]
#[CoversClass(NovaPayRefundStatusForm::class)]
#[CoversClass(NovaPayPaymentStatusForm::class)]
#[CoversClass(CompleteHoldRequest::class)]
#[CoversClass(VoidRequest::class)]
#[CoversClass(SessionStatusResponse::class)]
#[Group('commerce_novapay')]
final class NovaPayPaymentTest extends OrderKernelTestBase {

  /**
   * {@inheritdoc}
   *
   * @var list<string>
   */
  protected static $modules = [
    'commerce_checkout',
    'commerce_payment',
    'commerce_novapay',
    'telephone',
  ];

  /**
   * The tested payment gateway.
   */
  private PaymentGateway $paymentGateway;

  /**
   * The parent order.
   */
  private Order $order;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('commerce_payment');
    $this->installSchema(
      'commerce_novapay',
      [
        'commerce_novapay_postback_event',
        'commerce_novapay_refund_ledger',
      ],
    );
    $this->installConfig(['commerce_payment']);
    $this->container->get('commerce_price.currency_importer')->import('UAH');

    $this->paymentGateway = PaymentGateway::create([
      'id' => 'novapay_test',
      'label' => 'NovaPay',
      'plugin' => 'novapay',
    ]);
    $this->paymentGateway->save();

    $order_item = OrderItem::create([
      'type' => 'test',
      'title' => 'NovaPay test product',
      'quantity' => '1',
      'unit_price' => new Price('30', 'UAH'),
    ]);
    $order_item->save();

    $this->order = Order::create([
      'type' => 'default',
      'store_id' => $this->store->id(),
      'order_items' => [$order_item],
    ]);
    $this->order->save();
    $this->reloadOrder();
  }

  /**
   * Tests plugin binding, workflow states, transitions, and bundle fields.
   */
  public function testPaymentTypeDefinition(): void {
    $payment_type = $this->container
      ->get('plugin.manager.commerce_payment_type')
      ->createInstance('novapay_payment');
    self::assertInstanceOf(NovaPayPayment::class, $payment_type);
    self::assertSame('novapay_payment', $payment_type->getWorkflowId());
    self::assertSame(
      'novapay_payment',
      $this->paymentGateway->getPlugin()->getPaymentType()->getPluginId(),
    );
    self::assertSame(
      NovaPayPaymentOffsiteForm::class,
      $this->paymentGateway->getPlugin()->getFormClass('offsite-payment'),
    );
    self::assertInstanceOf(
      SupportsAuthorizationsInterface::class,
      $this->paymentGateway->getPlugin(),
    );
    self::assertSame(
      NovaPayCaptureForm::class,
      $this->paymentGateway->getPlugin()->getFormClass('capture-payment'),
    );
    self::assertSame(
      NovaPayVoidForm::class,
      $this->paymentGateway->getPlugin()->getFormClass('void-payment'),
    );
    self::assertInstanceOf(
      SupportsRefundsInterface::class,
      $this->paymentGateway->getPlugin(),
    );
    self::assertSame(
      NovaPayRefundForm::class,
      $this->paymentGateway->getPlugin()->getFormClass('refund-payment'),
    );
    self::assertSame(
      NovaPayRefundStatusForm::class,
      $this->paymentGateway->getPlugin()->getFormClass(
        'check-refund-status',
      ),
    );
    self::assertSame(
      NovaPayPaymentStatusForm::class,
      $this->paymentGateway->getPlugin()->getFormClass(
        'check-payment-status',
      ),
    );

    /** @var \Drupal\state_machine\WorkflowManagerInterface $workflow_manager */
    $workflow_manager = $this->container->get('plugin.manager.workflow');
    self::assertInstanceOf(
      WorkflowManagerInterface::class,
      $workflow_manager,
    );
    $workflow = $workflow_manager->createInstance('novapay_payment');
    self::assertInstanceOf(WorkflowInterface::class, $workflow);
    self::assertSame(
      [
        'pending',
        'authorization',
        'completed',
        'partially_refunded',
        'refunded',
        'authorization_voided',
        'expired',
        'failed',
      ],
      array_keys($workflow->getStates()),
    );
    self::assertNotNull(
      $workflow->findTransition('pending', 'authorization'),
    );
    self::assertNotNull(
      $workflow->findTransition('authorization', 'completed'),
    );
    self::assertNotNull(
      $workflow->findTransition('completed', 'partially_refunded'),
    );
    self::assertNotNull(
      $workflow->findTransition('partially_refunded', 'refunded'),
    );
    self::assertNotNull(
      $workflow->findTransition('authorization', 'authorization_voided'),
    );
    self::assertSame(
      $workflow->getState('refunded')->getLabel(),
      $workflow->getState('authorization_voided')->getLabel(),
    );
    self::assertNotNull($workflow->findTransition('pending', 'expired'));
    self::assertNotNull($workflow->findTransition('pending', 'failed'));
    self::assertNotNull($workflow->findTransition('failed', 'pending'));
    self::assertNotNull(
      $workflow->findTransition('failed', 'authorization'),
    );
    self::assertNotNull($workflow->findTransition('failed', 'completed'));
    self::assertNull($workflow->findTransition('expired', 'completed'));

    $field_definitions = $this->container
      ->get('entity_field.manager')
      ->getFieldDefinitions('commerce_payment', 'novapay_payment');
    self::assertArrayHasKey('novapay_operation_id', $field_definitions);
    self::assertArrayHasKey('novapay_external_id', $field_definitions);
    self::assertArrayHasKey('novapay_payment_url', $field_definitions);
    self::assertArrayHasKey('novapay_pending_operation', $field_definitions);
    self::assertArrayHasKey('novapay_pending_amount', $field_definitions);
    self::assertArrayHasKey('novapay_pending_refund', $field_definitions);
    self::assertArrayHasKey('expires', $field_definitions);

  }

  /**
   * Tests that missing recipient data limits capture to the full amount.
   */
  public function testCaptureFormLocksAmountWithoutRecipientIdentifier(): void {
    $gateway_uuid = $this->paymentGateway->uuid();
    self::assertIsString($gateway_uuid);
    $credentials = new Credentials(
      NovaPayMode::Test,
      '2',
      'private',
      'public',
    );
    $without_recipient = new RuntimeConfiguration(
      new RuntimeProfile(
        NovaPayMode::Test,
        NULL,
        TransactionMode::Hold,
        '',
        FALSE,
      ),
      $credentials,
    );
    $with_recipient = new RuntimeConfiguration(
      new RuntimeProfile(
        NovaPayMode::Test,
        NULL,
        TransactionMode::Hold,
        '31316718',
        FALSE,
      ),
      $credentials,
    );
    $resolver = $this->createMock(CredentialResolverInterface::class);
    $resolver->expects(self::exactly(2))
      ->method('resolveRuntimeConfiguration')
      ->with($gateway_uuid)
      ->willReturn($without_recipient, $with_recipient);
    $plugin = $this->paymentGateway->getPlugin();
    $resolver_property = new \ReflectionProperty(
      NovaPayConfigurationGatewayBase::class,
      'credentialResolver',
    );
    $resolver_property->setValue($plugin, $resolver);

    $payment = $this->createAuthorizationPayment('capture-form-session');
    $plugin_form = new NovaPayCaptureForm();
    $plugin_form->setEntity($payment);
    $plugin_form->setPlugin($plugin);
    $form = $plugin_form->buildConfigurationForm([], new FormState());

    self::assertArrayHasKey('recipient_identifier_notice', $form);
    self::assertTrue($form['amount']['#disabled']);
    self::assertSame(
      '30.00',
      $form['amount']['number']['#default_value'],
    );
    self::assertStringContainsString(
      'Partial capture is unavailable',
      (string) $form['recipient_identifier_notice']['#markup'],
    );

    $form = $plugin_form->buildConfigurationForm([], new FormState());

    self::assertArrayNotHasKey('recipient_identifier_notice', $form);
    self::assertArrayNotHasKey('#disabled', $form['amount']);
    $amount_element = NumberElement::preRenderNumber($form['amount']['number']);
    self::assertSame('number', $amount_element['#attributes']['type']);
    self::assertSame('0.01', $amount_element['#attributes']['step']);
    self::assertSame('0.01', $amount_element['#attributes']['min']);
    self::assertSame('30.00', $amount_element['#attributes']['max']);
    self::assertArrayNotHasKey('#min', $amount_element);
    self::assertArrayNotHasKey('#max', $amount_element);
    self::assertSame('UAH', $form['amount']['currency_code']['#value']);
    foreach (['-1', '0', '0.01', '29.99', '30.01'] as $value) {
      $amount_element['#value'] = $value;
      $state = new FormState();
      NumberElement::validateNumber($amount_element, $state, $form);
      self::assertFalse($state->hasAnyErrors());
    }
  }

  /**
   * Tests unique event claims and the non-sensitive journal schema.
   */
  public function testPostbackEventUniqueness(): void {
    $repository = $this->container->get(
      'commerce_novapay.postback.event_repository',
    );
    self::assertInstanceOf(PostbackEventRepositoryInterface::class, $repository);
    $calls = 0;
    $processor = static function () use (&$calls): PostbackOutcome {
      $calls++;
      return PostbackOutcome::Applied;
    };

    $first = $repository->processOnce(
      hash('sha256', 'raw-body-with-pii'),
      'session-id',
      'novapay_test',
      NovaPayStatus::Paid,
      $processor,
    );
    $duplicate = $repository->processOnce(
      hash('sha256', 'raw-body-with-pii'),
      'session-id',
      'novapay_test',
      NovaPayStatus::Paid,
      $processor,
    );

    self::assertSame(PostbackOutcome::Applied, $first);
    self::assertNull($duplicate);
    self::assertSame(1, $calls);

    $database = $this->container->get('database');
    $row = $database->select('commerce_novapay_postback_event', 'event')
      ->fields('event')
      ->execute()
      ->fetchAssoc();
    self::assertIsArray($row);
    self::assertSame(hash('sha256', 'raw-body-with-pii'), $row['event_key']);
    self::assertSame('session-id', $row['session_id']);
    self::assertSame('novapay_test', $row['gateway_id']);
    self::assertSame('paid', $row['status']);
    self::assertSame('1', (string) $row['signature_valid']);
    self::assertSame('applied', $row['outcome']);
    self::assertArrayNotHasKey('raw_body', $row);
    self::assertArrayNotHasKey('payload', $row);
  }

  /**
   * Tests that an unknown payment remains eligible for a later replay.
   */
  public function testUnknownPostbackIsNotClaimed(): void {
    $repository = $this->container->get(
      'commerce_novapay.postback.event_repository',
    );
    self::assertInstanceOf(
      PostbackEventRepositoryInterface::class,
      $repository,
    );
    $event_key = hash('sha256', 'postback-before-payment-save');
    $database = $this->container->get('database');

    $unknown = $repository->processOnce(
      $event_key,
      'late-session-id',
      'novapay_test',
      NovaPayStatus::Paid,
      static fn (): PostbackOutcome => PostbackOutcome::UnknownPayment,
    );
    self::assertSame(PostbackOutcome::UnknownPayment, $unknown);
    self::assertFalse($database
      ->select('commerce_novapay_postback_event', 'event')
      ->fields('event', ['event_key'])
      ->condition('event_key', $event_key)
      ->execute()
      ->fetchField());

    // Simulate an unknown claim left by an older module version.
    $database->insert('commerce_novapay_postback_event')
      ->fields([
        'event_key' => $event_key,
        'session_id' => 'late-session-id',
        'gateway_id' => 'novapay_test',
        'status' => NovaPayStatus::Paid->value,
        'received' => 1,
        'signature_valid' => 1,
        'outcome' => PostbackOutcome::UnknownPayment->value,
      ])
      ->execute();

    $applied = $repository->processOnce(
      $event_key,
      'late-session-id',
      'novapay_test',
      NovaPayStatus::Paid,
      static fn (): PostbackOutcome => PostbackOutcome::Applied,
    );
    self::assertSame(PostbackOutcome::Applied, $applied);
    self::assertSame('applied', $database
      ->select('commerce_novapay_postback_event', 'event')
      ->fields('event', ['outcome'])
      ->condition('event_key', $event_key)
      ->execute()
      ->fetchField());
  }

  /**
   * Tests released hold display without mutating payment accounting.
   */
  public function testReleasedHoldRefundDisplay(): void {
    $storage = $this->container->get('entity_type.manager')->getStorage('commerce_payment');
    foreach (['authorization_voided' => '30', 'authorization' => '0', 'refunded' => '5'] as $state => $expected) {
      $payment = $storage->create([
        'payment_gateway' => $this->paymentGateway,
        'order_id' => $this->order->id(),
        'amount' => new Price('30', 'UAH'),
        'refunded_amount' => new Price($state === 'refunded' ? '5' : '0', 'UAH'),
        'state' => $state,
      ]);
      assert($payment instanceof PaymentInterface);
      $before = $payment->getRefundedAmount();
      $items = $payment->get('refunded_amount');
      $formatter = $this->container->get('plugin.manager.field.formatter')->getInstance([
        'field_definition' => $items->getFieldDefinition(),
        'configuration' => ['type' => 'commerce_price_default'],
        'view_mode' => 'default',
      ]);
      self::assertInstanceOf(FormatterInterface::class, $formatter);
      $elements = $formatter->viewElements($items, 'en');
      $formatted = $this->container->get('commerce_price.currency_formatter')->format($expected, 'UAH', ['currency_display' => 'symbol']);
      self::assertSame($formatted, $elements[0]['#markup']);
      self::assertEquals($before, $payment->getRefundedAmount());
      self::assertSame($state, $payment->getState()->getId());
    }
  }

  /**
   * Tests field persistence and total_paid refund semantics.
   */
  public function testPaymentOrderIntegration(): void {
    /** @var \Drupal\commerce_payment\PaymentStorageInterface $payment_storage */
    $payment_storage = $this->container
      ->get('entity_type.manager')
      ->getStorage('commerce_payment');
    /** @var \Drupal\commerce_payment\Entity\PaymentInterface $payment */
    $payment = $payment_storage->create([
      'payment_gateway' => $this->paymentGateway,
      'order_id' => $this->order->id(),
      'amount' => new Price('30', 'UAH'),
      'state' => 'completed',
      'remote_id' => 'session-id',
      'remote_state' => 'paid',
      'novapay_operation_id' => 'operation-id',
      'novapay_external_id' => 'ORDER-1',
      'novapay_payment_url' => 'https://example.com/pay/session-id',
    ]);
    self::assertInstanceOf(PaymentInterface::class, $payment);
    self::assertSame('novapay_payment', $payment->bundle());
    $payment->save();

    $payment = $this->reloadEntity($payment);
    assert($payment instanceof PaymentInterface);
    self::assertSame(
      'operation-id',
      $payment->get('novapay_operation_id')->value,
    );
    self::assertSame(
      'ORDER-1',
      $payment->get('novapay_external_id')->value,
    );
    self::assertSame(
      'https://example.com/pay/session-id',
      $payment->get('novapay_payment_url')->value,
    );
    self::assertGreaterThan(0, $payment->getCompletedTime());

    $this->order->save();
    $this->reloadOrder();
    self::assertEquals(
      new Price('30', 'UAH'),
      $this->order->getTotalPaid(),
    );

    $payment->setRefundedAmount(new Price('10', 'UAH'));
    $payment->setState('partially_refunded');
    $payment->save();
    $this->order->save();
    $this->reloadOrder();
    self::assertEquals(
      new Price('20', 'UAH'),
      $this->order->getTotalPaid(),
    );

    $payment->setRefundedAmount(new Price('30', 'UAH'));
    $payment->setState('refunded');
    $payment->save();
    $this->order->save();
    $this->reloadOrder();
    self::assertEquals(
      new Price('0', 'UAH'),
      $this->order->getTotalPaid(),
    );
  }

  /**
   * Tests that a stale created event cannot reactivate a failed payment.
   */
  public function testCreatedStatusDoesNotRecoverFailedPayment(): void {
    $payment_storage = $this->container
      ->get('entity_type.manager')
      ->getStorage('commerce_payment');
    $payment = $payment_storage->create([
      'payment_gateway' => $this->paymentGateway,
      'order_id' => $this->order->id(),
      'amount' => new Price('30', 'UAH'),
      'state' => 'pending',
      'remote_id' => 'failed-session-id',
      'remote_state' => NovaPayStatus::Processing->value,
    ]);
    self::assertInstanceOf(PaymentInterface::class, $payment);
    $payment->save();

    $mapper = new PaymentStatusMapper(
      $this->container->get('commerce_payment.order_updater'),
    );
    self::assertTrue($mapper->apply($payment, NovaPayStatus::Failed));
    self::assertFalse($mapper->apply($payment, NovaPayStatus::Created));

    $payment = $this->reloadEntity($payment);
    self::assertInstanceOf(PaymentInterface::class, $payment);
    self::assertSame('failed', $payment->getState()->getId());
    self::assertSame(NovaPayStatus::Failed->value, $payment->getRemoteState());
  }

  /**
   * Tests zero amounts request the remaining capture, not the order value.
   */
  public function testZeroItemAmountsRefundRemainingCapture(): void {
    $payment = $this->createCompletedPayment('zero-item-refund');
    $payment->setAmount(new Price('4.50', 'UAH'));
    $payment->setRefundedAmount(new Price('2', 'UAH'));
    $payment->setState('partially_refunded');
    $payment->save();
    $id = (int) $this->order->getItems()[0]->id();
    $api = $this->createMock(NovaPayApiClientInterface::class);
    $api->expects(self::once())->method('voidPayment')->with(
      self::anything(),
      self::callback(static fn (VoidRequest $request): bool => $request->toArray() === ['session_id' => 'zero-item-refund']),
    )->willReturn(new AcknowledgementResponse(200));
    $manager = $this->createRefundOperationManager($api);
    $manager->refund($payment, $this->createRuntimeProvider(), [$id => '0.00'], [$id => '0.00']);
    $payment = $this->reloadEntity($payment);
    self::assertInstanceOf(PaymentInterface::class, $payment);
    self::assertSame('2.5', $payment->get('novapay_pending_amount')->getString());
    self::assertEquals(new Price('2', 'UAH'), $payment->getRefundedAmount());
    self::assertSame(0, $this->countRefundLedgerRows($payment));
  }

  /**
   * Tests compensation below item price within a partial capture balance.
   */
  public function testExplicitItemCompensation(): void {
    $payment = $this->createCompletedPayment('item-compensation');
    $payment->setAmount(new Price('4.50', 'UAH'));
    $payment->save();
    $id = (int) $this->order->getItems()[0]->id();
    $builder = $this->container->get('commerce_novapay.refund_selection_builder');
    $selection = $builder->buildAmountSelection($payment, [$id => '1'], [$id => '2']);
    self::assertSame('1', $selection[0]->getQuantity());
    self::assertEquals(new Price('2', 'UAH'), $selection[0]->getAmount());
    foreach ([[$id => '-1'], [$id => '1.001'], [$id => '1e0'], [$id + 9999 => '1'], [$id => '5']] as $invalid) {
      try {
        $builder->buildAmountSelection($payment, [$id => '0'], $invalid);
        self::fail('Invalid compensation must be rejected.');
      }
      catch (InvalidRequestException $exception) {
        self::assertNotSame('', $exception->getMessage());
      }
    }
    $api = $this->createMock(NovaPayApiClientInterface::class);
    $api->expects(self::once())->method('voidPayment')->with(
      self::anything(),
      self::callback(static fn (VoidRequest $request): bool => $request->toArray()['operations'][0]['refund_amount'] === '2'),
    )->willReturn(new AcknowledgementResponse(200));
    $manager = $this->createRefundOperationManager($api);
    $manager->refund($payment, $this->createRuntimeProvider(), [$id => '0'], [$id => '2']);
    $payment = $this->reloadEntity($payment);
    self::assertInstanceOf(PaymentInterface::class, $payment);
    self::assertEquals(new Price('0', 'UAH'), $payment->getRefundedAmount());
    self::assertSame(0, $this->countRefundLedgerRows($payment));
    $event = NormalizedPostbackEvent::fromValues('item-compensation', 'paid', [(string) $this->order->id()], ['2']);
    $manager->confirm($payment, $event, hash('sha256', 'item-compensation'));
    $payment = $this->reloadEntity($payment);
    self::assertInstanceOf(PaymentInterface::class, $payment);
    self::assertEquals(new Price('2', 'UAH'), $payment->getRefundedAmount());
    self::assertEquals(new Price('2.50', 'UAH'), $payment->getBalance());
    self::assertSame('0', $this->container->get('commerce_novapay.refund_ledger')->getRefundedQuantities((int) $payment->id())[$id]);
    $api->expects(self::never())->method('getStatus');
    try {
      $manager->refund($payment, $this->createRuntimeProvider(), [$id => '0'], [$id => '3']);
      self::fail('Compensation must not exceed the remaining captured balance.');
    }
    catch (InvalidRequestException) {
      self::assertSame(1, $this->countRefundLedgerRows($payment));
    }
  }

  /**
   * Tests partial quantities, postback confirmation, and cumulative limits.
   */
  public function testItemRefundLedgerWaitsForPostback(): void {
    $this->setQuantityStep('0.5');
    $payment = $this->createCompletedPayment('partial-refund-session');
    $order_item_id = (int) $this->order->getItems()[0]->id();
    $requests = [];
    $api_client = $this->createMock(NovaPayApiClientInterface::class);
    $api_client->method('voidPayment')->willReturnCallback(
      static function (
        RuntimeConfigurationProviderInterface $gateway,
        VoidRequest $request,
      ) use (&$requests): AcknowledgementResponse {
        $requests[] = $request->toArray();
        return new AcknowledgementResponse(200);
      },
    );
    $manager = $this->createRefundOperationManager($api_client);

    $manager->refund(
      $payment,
      $this->createRuntimeProvider(),
      [$order_item_id => '0.5'],
    );
    self::assertSame([[
      'session_id' => 'partial-refund-session',
      'operations' => [[
        'id' => 'operation-id',
        'refund_amount' => '15',
      ]],
    ]], $requests);

    $payment = $this->reloadEntity($payment);
    self::assertInstanceOf(PaymentInterface::class, $payment);
    self::assertSame('completed', $payment->getState()->getId());
    self::assertEquals(new Price('0', 'UAH'), $payment->getRefundedAmount());
    self::assertSame('refund', $payment
      ->get('novapay_pending_operation')->getString());
    self::assertSame(0, $this->countRefundLedgerRows($payment));

    $processing_event = NormalizedPostbackEvent::fromValues(
      'partial-refund-session',
      'processing_void',
      [(string) $this->order->id()],
    );
    $manager->confirm(
      $payment,
      $processing_event,
      hash('sha256', 'partial-processing'),
    );
    self::assertSame(0, $this->countRefundLedgerRows($payment));
    $payment = $this->reloadEntity($payment);
    self::assertInstanceOf(PaymentInterface::class, $payment);
    $event = NormalizedPostbackEvent::fromValues(
      'partial-refund-session',
      'paid',
      [(string) $this->order->id()],
      ['15'],
    );
    $manager->confirm($payment, $event, hash('sha256', 'partial-confirmed'));
    $payment = $this->reloadEntity($payment);
    self::assertInstanceOf(PaymentInterface::class, $payment);
    self::assertSame('partially_refunded', $payment->getState()->getId());
    self::assertEquals(new Price('15', 'UAH'), $payment->getRefundedAmount());
    self::assertTrue($payment->get('novapay_pending_operation')->isEmpty());
    self::assertSame(1, $this->countRefundLedgerRows($payment));

    try {
      $manager->refund(
        $payment,
        $this->createRuntimeProvider(),
        [$order_item_id => '0.500001'],
      );
      self::fail('A refund must not exceed the remaining paid quantity.');
    }
    catch (InvalidRequestException $exception) {
      self::assertStringContainsString('quantity', $exception->getMessage());
    }
    self::assertCount(1, $requests);

    $manager->refund(
      $payment,
      $this->createRuntimeProvider(),
      [$order_item_id => '0.5'],
    );
    $payment = $this->reloadEntity($payment);
    self::assertInstanceOf(PaymentInterface::class, $payment);
    $final_event = NormalizedPostbackEvent::fromValues(
      'partial-refund-session',
      'voided',
      [(string) $this->order->id()],
    );
    $manager->confirm(
      $payment,
      $final_event,
      hash('sha256', 'full-confirmed'),
    );
    (new PaymentStatusMapper(
      $this->container->get('commerce_payment.order_updater'),
    ))->apply($payment, NovaPayStatus::Voided);
    $payment = $this->reloadEntity($payment);
    self::assertInstanceOf(PaymentInterface::class, $payment);
    self::assertSame('refunded', $payment->getState()->getId());
    self::assertEquals(new Price('30', 'UAH'), $payment->getRefundedAmount());
    self::assertSame(2, $this->countRefundLedgerRows($payment));
    self::assertFalse($manager->canRefund($payment));
  }

  /**
   * Tests that item selections aggregate into the original NovaPay operation.
   */
  public function testPartialRefundUsesThePrimaryPaymentOperation(): void {
    $this->setQuantityStep('0.5');
    $additional_item = OrderItem::create([
      'type' => 'test',
      'title' => 'Additional NovaPay product',
      'quantity' => '1',
      'unit_price' => new Price('5', 'UAH'),
    ]);
    $additional_item->save();
    $this->order->addItem($additional_item);
    $this->order->save();
    $this->reloadOrder();

    $payment = $this->createCompletedPayment('aggregate-refund-session');
    $items = $this->order->getItems();
    $requests = [];
    $api_client = $this->createMock(NovaPayApiClientInterface::class);
    $api_client->method('voidPayment')->willReturnCallback(
      static function (
        RuntimeConfigurationProviderInterface $gateway,
        VoidRequest $request,
      ) use (&$requests): AcknowledgementResponse {
        $requests[] = $request->toArray();
        return new AcknowledgementResponse(200);
      },
    );

    $this->createRefundOperationManager($api_client)->refund(
      $payment,
      $this->createRuntimeProvider(),
      [
        (int) $items[0]->id() => '0.5',
        (int) $items[1]->id() => '1',
      ],
    );

    self::assertSame([[
      'session_id' => 'aggregate-refund-session',
      'operations' => [[
        'id' => 'operation-id',
        'refund_amount' => '20',
      ]],
    ]], $requests);
  }

  /**
   * Tests that an empty item selection does not contact NovaPay.
   */
  public function testEmptySelectionRequestsFullRefund(): void {
    foreach (['', '0', '0.00', '  '] as $index => $quantity) {
      $session = 'full-refund-session-' . $index;
      $payment = $this->createCompletedPayment($session);
      $order_item_id = (int) $this->order->getItems()[0]->id();
      $api_client = $this->createMock(NovaPayApiClientInterface::class);
      $api_client->expects(self::once())->method('voidPayment')
        ->with(self::anything(), self::callback(static fn (VoidRequest $request): bool =>
          $request->toArray() === ['session_id' => $session]))
        ->willReturn(new AcknowledgementResponse(200));
      $manager = $this->createRefundOperationManager($api_client);
      $manager->refund($payment, $this->createRuntimeProvider(), [$order_item_id => $quantity]);
      $payment = $this->reloadEntity($payment);
      self::assertInstanceOf(PaymentInterface::class, $payment);
      self::assertSame('30', $payment->get('novapay_pending_amount')->getString());
      self::assertSame('completed', $payment->getState()->getId());
      self::assertTrue($payment->getRefundedAmount()->isZero());
    }
  }

  /**
   * Tests that a full item selection is not downgraded by price allocation.
   */
  public function testCompleteQuantityBelowBalanceRequestsFullRefund(): void {
    $payment = $this->createCompletedPayment('allocation-full-session');
    $order_item = $this->order->getItems()[0];
    $order_item->setUnitPrice(new Price('29', 'UAH'));
    $order_item->save();
    $order_item_id = (int) $order_item->id();
    $api_client = $this->createMock(NovaPayApiClientInterface::class);
    $api_client->expects(self::once())->method('voidPayment')
      ->with(
        self::isInstanceOf(RuntimeConfigurationProviderInterface::class),
        self::callback(static fn (VoidRequest $request): bool =>
                    $request->toArray() === [
                      'session_id' => 'allocation-full-session',
                    ]),
      )
      ->willReturn(new AcknowledgementResponse(200));

    $this->createRefundOperationManager($api_client)->refund(
      $payment,
      $this->createRuntimeProvider(),
      [$order_item_id => '1'],
    );
  }

  /**
   * Tests session void overrides partial intent without repeating the refund.
   */
  #[DataProvider('sessionVoidEvidenceProvider')]
  public function testSessionVoidCompletesPendingPartialRefund(?string $reported, bool $manual): void {
    $this->setQuantityStep('0.5');
    $payment = $this->createCompletedPayment('partial-voided-session');
    $item_id = (int) $this->order->getItems()[0]->id();
    $api = $this->createMock(NovaPayApiClientInterface::class);
    $api->expects(self::once())->method('voidPayment')->willReturn(new AcknowledgementResponse(200));
    $operations = [['transaction_id' => 'operation-id', 'status' => 'voided']];
    if ($reported !== NULL) {
      $operations[0]['refunded_amount'] = $reported;
    }
    if ($manual) {
      $api->expects(self::once())->method('getStatus')->willReturn(SessionStatusResponse::fromArray([
        'id' => 'partial-voided-session', 'status' => 'voided', 'operations' => $operations,
      ]));
    }
    else {
      $api->expects(self::never())->method('getStatus');
    }
    $manager = $this->createRefundOperationManager($api);
    $manager->refund($payment, $this->createRuntimeProvider(), [$item_id => '0.5']);
    $payment = $this->reloadEntity($payment);
    self::assertInstanceOf(PaymentInterface::class, $payment);
    if ($manual) {
      $result = $manager->checkStatus($payment, $this->createRuntimeProvider());
      if ($reported !== '30') {
        self::assertSame(RefundStatusCheckResult::Pending, $result);
        self::assertTrue($manager->canCheckStatus($payment));
        self::assertTrue($payment->getRefundedAmount()->isZero());
        return;
      }
      self::assertSame(RefundStatusCheckResult::Confirmed, $result);
    }
    $entry = ['external_id' => (string) $this->order->id(), 'amount' => '30'];
    if ($reported !== NULL) {
      $entry['refunded_amount'] = $reported;
    }
    $body = json_encode([
      'id' => 'partial-voided-session', 'status' => 'voided', 'payments' => [$entry],
    ], JSON_THROW_ON_ERROR);
    $processor = $this->createVerifiedPostbackProcessor();
    self::assertSame($manual ? PostbackOutcome::Ignored : PostbackOutcome::Applied,
      $processor->process($this->paymentGateway, $this->createRuntimeProvider(), $body, 'verified')->getOutcome());
    self::assertSame(PostbackOutcome::Duplicate,
      $processor->process($this->paymentGateway, $this->createRuntimeProvider(), $body, 'verified')->getOutcome());
    $payment = $this->reloadEntity($payment);
    self::assertInstanceOf(PaymentInterface::class, $payment);
    self::assertSame('refunded', $payment->getState()->getId());
    self::assertSame('voided', $payment->getRemoteState());
    self::assertEquals(new Price('30', 'UAH'), $payment->getRefundedAmount());
    self::assertTrue($payment->getBalance()->isZero());
    self::assertFalse($manager->canCheckStatus($payment));
    self::assertFalse($manager->canRefund($payment));
    self::assertTrue($payment->get('novapay_pending_refund')->isEmpty());
    self::assertSame(1, $this->countRefundLedgerRows($payment));
    $row = $this->container->get('database')->select('commerce_novapay_refund_ledger', 'r')
      ->fields('r')->condition('payment_id', $payment->id())->execute()->fetchAssoc();
    self::assertIsArray($row);
    self::assertNull($row['order_item_id']);
    self::assertEquals('30', $row['amount']);
    $this->reloadOrder();
    self::assertEquals(new Price('0', 'UAH'), $this->order->getTotalPaid());
    self::assertFalse($this->order->isPaid());
  }

  /**
   * Provides missing, zero and full amounts for both confirmation paths.
   *
   * @return iterable<string, array{?string, bool}>
   *   Reported refund amount and whether to reconcile through get-status.
   */
  public static function sessionVoidEvidenceProvider(): iterable {
    foreach (['missing' => NULL, 'zero' => '0', 'full' => '30'] as $label => $amount) {
      yield $label . ' callback' => [$amount, FALSE];
      yield $label . ' status' => [$amount, TRUE];
    }
  }

  /**
   * Tests V1 voids use exact status evidence and never guess refund amounts.
   */
  #[DataProvider('ambiguousVoidProvider')]
  public function testAmbiguousV1VoidStatusEvidence(?string $reported, string $operation, string $session, bool $manual): void {
    $this->setQuantityStep('0.5');
    $payment = $this->createCompletedPayment('v1-refund-lookup');
    $api = $this->createMock(NovaPayApiClientInterface::class);
    $api->expects(self::once())->method('voidPayment')->willReturn(new AcknowledgementResponse(200));
    $ops = $reported === NULL ? [] : [[
      'transaction_id' => $operation,
      'status' => 'voided',
      'refunded_amount' => $reported,
    ]];
    if ($operation === 'transport') {
      $api->expects(self::once())->method('getStatus')->willThrowException(ApiTransportException::requestFailed());
    }
    else {
      $api->expects(self::exactly(!$manual && $reported === '15' && $operation === 'operation-id' && $session === 'v1-refund-lookup' ? 2 : 1))->method('getStatus')->willReturn(SessionStatusResponse::fromArray([
        'id' => $session, 'status' => 'voided', 'operations' => $ops,
      ]));
    }
    $manager = $this->createRefundOperationManager($api);
    $manager->refund($payment, $this->createRuntimeProvider(), [(int) $this->order->getItems()[0]->id() => '0.5']);
    $payment = $this->reloadEntity($payment);
    self::assertInstanceOf(PaymentInterface::class, $payment);
    $valid = $operation === 'operation-id' && $session === 'v1-refund-lookup' && in_array($reported, ['15', '30'], TRUE);
    if ($manual) {
      if ($session !== 'v1-refund-lookup') {
        $this->expectException(PaymentGatewayException::class);
      }
      $result = $manager->checkStatus($payment, $this->createRuntimeProvider());
      self::assertSame($valid ? RefundStatusCheckResult::Confirmed : RefundStatusCheckResult::Pending, $result);
    }
    else {
      $body = json_encode([
        'id' => 'v1-refund-lookup', 'status' => 'voided',
        'external_id' => (string) $this->order->id(), 'amount' => 30,
      ], JSON_THROW_ON_ERROR);
      $processor = $this->createVerifiedPostbackProcessor($api);
      try {
        self::assertSame(PostbackOutcome::Applied, $processor->process($this->paymentGateway, $this->createRuntimeProvider(), $body, 'verified')->getOutcome());
        self::assertTrue($valid);
        self::assertSame(PostbackOutcome::Duplicate, $processor->process($this->paymentGateway, $this->createRuntimeProvider(), $body, 'verified')->getOutcome());
      }
      catch (\UnexpectedValueException | ApiTransportException) {
        self::assertFalse($valid);
        self::assertSame('0', (string) $this->container->get('database')->select('commerce_novapay_postback_event')->countQuery()->execute()->fetchField());
      }
    }
    $payment = $this->reloadEntity($payment);
    self::assertInstanceOf(PaymentInterface::class, $payment);
    self::assertEquals(new Price($valid ? $reported : '0', 'UAH'), $payment->getRefundedAmount());
    self::assertSame($valid ? ($reported === '30' ? 'refunded' : 'partially_refunded') : 'completed', $payment->getState()->getId());
    self::assertSame(!$valid, $manager->canCheckStatus($payment));
    self::assertSame($valid ? 1 : 0, $this->countRefundLedgerRows($payment));
    $this->reloadOrder();
    self::assertEquals(new Price($valid && $reported === '15' ? '15' : '30', 'UAH'), $this->order->getTotalPrice());
  }

  /**
   * Provides exact, missing, inconsistent and mismatched status evidence.
   *
   * @return iterable<string, array{?string, string, string, bool}>
   *   Status evidence and reconciliation mode.
   */
  public static function ambiguousVoidProvider(): iterable {
    foreach ([NULL, '0', '5', '15', '20', '30', '35'] as $amount) {
      foreach ([FALSE, TRUE] as $manual) {
        yield ($amount ?? 'missing') . ($manual ? ' manual' : ' callback') => [
          $amount, 'operation-id', 'v1-refund-lookup', $manual,
        ];
      }
    }
    yield 'wrong operation' => ['15', 'other-operation', 'v1-refund-lookup', FALSE];
    yield 'wrong session' => ['15', 'operation-id', 'other-session', FALSE];
    yield 'transport failure' => ['15', 'transport', 'v1-refund-lookup', FALSE];
  }

  /**
   * Tests V1 void closes refund access while preserving partial accounting.
   */
  #[DataProvider('repeatedV1RefundProvider')]
  public function testRepeatedPartialRefundWithIdenticalV1Callback(string $second): void {
    $this->container->get('current_user')->setAccount(User::create(['uid' => 1]));
    $this->setQuantityStep('0.1');
    $payment = $this->createCompletedPayment('repeated-v1-refund');
    $api = $this->createMock(NovaPayApiClientInterface::class);
    $api->expects(self::once())->method('voidPayment')->willReturn(new AcknowledgementResponse(200));
    $responses = [];
    foreach ($second === 'external' ? ['3', '3', '30'] : ['3', '3'] as $amount) {
      $responses[] = SessionStatusResponse::fromArray([
        'id' => 'repeated-v1-refund', 'status' => 'voided',
        'operations' => [['transaction_id' => 'operation-id', 'refunded_amount' => $amount]],
      ]);
    }
    $api->expects(self::exactly(count($responses)))->method('getStatus')->willReturnOnConsecutiveCalls(...$responses);
    $manager = $this->createRefundOperationManager($api);
    $processor = $this->createVerifiedPostbackProcessor($api);
    $body = json_encode([
      'id' => 'repeated-v1-refund', 'status' => 'voided',
      'external_id' => (string) $this->order->id(), 'amount' => 30,
    ], JSON_THROW_ON_ERROR);
    self::assertTrue($payment->access('refund', $this->container->get('current_user')));
    $manager->refund($payment, $this->createRuntimeProvider(), [(int) $this->order->getItems()[0]->id() => '0.1']);
    self::assertSame(PostbackOutcome::Applied, $processor->process($this->paymentGateway, $this->createRuntimeProvider(), $body, 'verified')->getOutcome());
    $payment = $this->reloadEntity($payment);
    self::assertInstanceOf(PaymentInterface::class, $payment);
    self::assertEquals(new Price('3', 'UAH'), $payment->getRefundedAmount());
    self::assertSame('partially_refunded', $payment->getState()->getId());
    self::assertSame('voided', $payment->getRemoteState());
    self::assertFalse($manager->canRefund($payment));
    $plugin = $this->paymentGateway->getPlugin();
    self::assertInstanceOf(NovaPay::class, $plugin);
    self::assertFalse($plugin->canRefundPayment($payment));
    self::assertArrayNotHasKey('refund', $plugin->buildPaymentOperations($payment));
    self::assertTrue($payment->get('novapay_pending_operation')->isEmpty());
    $route_match = new RouteMatch(
      'entity.commerce_payment.operation_form',
      new Route('/admin/commerce/orders/{commerce_order}/payments/{commerce_payment}/operation/{operation}'),
      ['commerce_order' => $this->order, 'commerce_payment' => $payment, 'operation' => 'refund'],
    );
    $this->container->get('entity_type.manager')->getAccessControlHandler('commerce_payment')->resetCache();
    $access = (new PaymentOperationAccessCheck())->access($route_match, $this->container->get('current_user'));
    self::assertFalse($access->isAllowed());
    // Simulate an already-journaled callback from the previous module version.
    $payment->setRemoteState('paid')->save();
    self::assertSame(PostbackOutcome::Duplicate, $processor->process($this->paymentGateway, $this->createRuntimeProvider(), $body, 'verified')->getOutcome());
    $payment = $this->reloadEntity($payment);
    self::assertInstanceOf(PaymentInterface::class, $payment);
    self::assertSame('voided', $payment->getRemoteState());
    self::assertSame('partially_refunded', $payment->getState()->getId());
    self::assertEquals(new Price('3', 'UAH'), $payment->getRefundedAmount());
    self::assertSame(1, $this->countRefundLedgerRows($payment));
    $late = json_encode([
      'id' => 'repeated-v1-refund', 'status' => 'processing_void',
      'external_id' => (string) $this->order->id(),
    ], JSON_THROW_ON_ERROR);
    $processor->process($this->paymentGateway, $this->createRuntimeProvider(), $late, 'verified');
    $payment = $this->reloadEntity($payment);
    self::assertInstanceOf(PaymentInterface::class, $payment);
    self::assertSame('voided', $payment->getRemoteState());
    if ($second === 'external') {
      self::assertSame(PostbackOutcome::Applied, $processor->process($this->paymentGateway, $this->createRuntimeProvider(), $body, 'verified')->getOutcome());
      $payment = $this->reloadEntity($payment);
      self::assertInstanceOf(PaymentInterface::class, $payment);
      self::assertEquals(new Price('30', 'UAH'), $payment->getRefundedAmount());
      self::assertSame('refunded', $payment->getState()->getId());
      self::assertSame(1, $this->countRefundLedgerRows($payment));
    }
    else {
      try {
        $manager->refund($payment, $this->createRuntimeProvider(), $second === 'full' ? [] : [(int) $this->order->getItems()[0]->id() => '0.1']);
        self::fail('A terminal void must block another refund before financial HTTP.');
      }
      catch (InvalidRequestException) {
        self::assertFalse($manager->canRefund($payment));
      }
    }
  }

  /**
   * Provides repeated partial, final and external refund confirmations.
   *
   * @return array<string, array{string}>
   *   Follow-up refund scenarios.
   */
  public static function repeatedV1RefundProvider(): array {
    return ['partial' => ['partial'], 'full' => ['full'], 'external' => ['external']];
  }

  /**
   * Tests an external full refund after an already confirmed partial refund.
   */
  public function testExternalSessionVoidUsesRemainingCapturedBalance(): void {
    $this->setQuantityStep('0.25');
    $payment = $this->createCompletedPayment('external-void');
    $item_id = (int) $this->order->getItems()[0]->id();
    $api = $this->createMock(NovaPayApiClientInterface::class);
    $api->expects(self::exactly(2))->method('voidPayment')->willReturn(new AcknowledgementResponse(200));
    $manager = $this->createRefundOperationManager($api);
    $manager->refund($payment, $this->createRuntimeProvider(), [$item_id => '0.5']);
    $processor = $this->createVerifiedPostbackProcessor();
    // An operation void inside a paid session must not complete the refund.
    $payload = [
      'id' => 'external-void', 'status' => 'paid',
      'payments' => [['external_id' => (string) $this->order->id(), 'amount' => '30']],
      'operations' => [['transaction_id' => 'operation-id', 'status' => 'voided']],
    ];
    $processor->process($this->paymentGateway, $this->createRuntimeProvider(), json_encode($payload, JSON_THROW_ON_ERROR), 'verified');
    $payment = $this->reloadEntity($payment);
    self::assertInstanceOf(PaymentInterface::class, $payment);
    self::assertSame('completed', $payment->getState()->getId());
    self::assertTrue($payment->getRefundedAmount()->isZero());
    self::assertTrue($manager->canCheckStatus($payment));
    // Confirm the selected half first, then initiate another partial intent.
    $manager->confirm($payment, NormalizedPostbackEvent::fromValues('external-void', 'paid', [], ['15']), hash('sha256', 'first-half'));
    $payment = $this->reloadEntity($payment);
    self::assertInstanceOf(PaymentInterface::class, $payment);
    $manager->refund($payment, $this->createRuntimeProvider(), [$item_id => '0.25']);
    $payload['status'] = 'voided';
    $body = json_encode($payload, JSON_THROW_ON_ERROR);
    $processor->process($this->paymentGateway, $this->createRuntimeProvider(), $body, 'verified');
    $payment = $this->reloadEntity($payment);
    self::assertInstanceOf(PaymentInterface::class, $payment);
    self::assertSame('refunded', $payment->getState()->getId());
    self::assertEquals(new Price('30', 'UAH'), $payment->getRefundedAmount());
    self::assertSame(2, $this->countRefundLedgerRows($payment));
    $sum = $this->container->get('database')->select('commerce_novapay_refund_ledger', 'r');
    $sum->addExpression('SUM(amount)', 'total');
    self::assertEquals('30', $sum->condition('payment_id', $payment->id())->execute()->fetchField());
  }

  /**
   * Tests ledger, pending intent and payment rollback before callback retry.
   */
  #[DataProvider('refundConfirmationRollbackProvider')]
  public function testRefundConfirmationRollsBackFailedOrderSave(bool $partial): void {
    $this->setQuantityStep('0.5');
    $payment = $this->createCompletedPayment('refund-rollback');
    $item_id = (int) $this->order->getItems()[0]->id();
    $api = $this->createMock(NovaPayApiClientInterface::class);
    $api->expects(self::once())->method('voidPayment')->willReturn(new AcknowledgementResponse(200));
    $manager = $this->createRefundOperationManager($api);
    $manager->refund($payment, $this->createRuntimeProvider(), [$item_id => '0.5']);
    $body = json_encode([
      'id' => 'refund-rollback', 'status' => $partial ? 'paid' : 'voided',
      'payments' => [[
        'external_id' => (string) $this->order->id(),
        'amount' => '30',
        'refunded_amount' => $partial ? '15' : '30',
      ]],
    ], JSON_THROW_ON_ERROR);
    $processor = $this->createVerifiedPostbackProcessor();
    $database = $this->container->get('database');
    $schema = rtrim($database->getPrefix(), '.');
    self::assertMatchesRegularExpression('/^[A-Za-z0-9_]+$/D', $schema);
    $trigger = sprintf('"%s"."novapay_refund_order_failure"', $schema);
    $database->query("CREATE TRIGGER $trigger BEFORE UPDATE ON commerce_order BEGIN SELECT RAISE(FAIL, 'forced refund order failure'); END", [], ['allow_delimiter_in_query' => TRUE]);
    try {
      $processor->process($this->paymentGateway, $this->createRuntimeProvider(), $body, 'verified');
      self::fail('A failed order save must roll back refund confirmation.');
    }
    catch (EntityStorageException $exception) {
      self::assertStringContainsString('forced refund order failure', $exception->getMessage());
    }
    finally {
      $database->query("DROP TRIGGER $trigger");
    }
    $payment = $this->reloadEntity($payment);
    self::assertInstanceOf(PaymentInterface::class, $payment);
    self::assertSame('completed', $payment->getState()->getId());
    self::assertTrue($payment->getRefundedAmount()->isZero());
    self::assertTrue($manager->canCheckStatus($payment));
    self::assertSame(0, $this->countRefundLedgerRows($payment));
    $this->reloadOrder();
    self::assertSame([], $this->order->getAdjustments(['novapay_partial_refund']));
    self::assertSame(PostbackOutcome::Applied, $processor->process($this->paymentGateway, $this->createRuntimeProvider(), $body, 'verified')->getOutcome());
    $payment = $this->reloadEntity($payment);
    self::assertInstanceOf(PaymentInterface::class, $payment);
    self::assertSame($partial ? 'partially_refunded' : 'refunded', $payment->getState()->getId());
    self::assertEquals(new Price($partial ? '15' : '30', 'UAH'), $payment->getRefundedAmount());
    self::assertSame(1, $this->countRefundLedgerRows($payment));
    $this->reloadOrder();
    self::assertCount($partial ? 1 : 0, $this->order->getAdjustments(['novapay_partial_refund']));
    self::assertSame($partial, $this->order->isPaid());
  }

  /**
   * Provides terminal and partial confirmation rollback cases.
   *
   * @return iterable<string, array{bool}>
   *   Whether to confirm a partial refund.
   */
  public static function refundConfirmationRollbackProvider(): iterable {
    yield 'full' => [FALSE];
    yield 'partial' => [TRUE];
  }

  /**
   * Tests successive partial refunds keep one adjustment and settle the order.
   */
  public function testPartialRefundAdjustmentsAccumulateWithoutDuplicates(): void {
    $payment = $this->createCompletedPayment('adjusted-refunds');
    $item = $this->order->getItems()[0];
    $item->setQuantity('6');
    $item->setUnitPrice(new Price('5', 'UAH'));
    $item->save();
    $this->order->set('state', 'completed');
    $this->order->recalculateTotalPrice();
    $this->order->save();
    $api = $this->createMock(NovaPayApiClientInterface::class);
    $api->expects(self::exactly(3))->method('voidPayment')->willReturn(new AcknowledgementResponse(200));
    $manager = $this->createRefundOperationManager($api);
    $processor = $this->createVerifiedPostbackProcessor();
    foreach (['5', '10'] as $index => $cumulative) {
      $manager->refund($payment, $this->createRuntimeProvider(), [(int) $item->id() => '1']);
      $this->reloadOrder();
      self::assertCount($index, $this->order->getAdjustments(['novapay_partial_refund']));
      $body = json_encode([
        'id' => 'adjusted-refunds', 'status' => 'paid',
        'payments' => [[
          'external_id' => (string) $this->order->id(),
          'amount' => '30',
          'refunded_amount' => $cumulative,
        ]],
      ], JSON_THROW_ON_ERROR);
      self::assertSame(PostbackOutcome::Applied, $processor->process($this->paymentGateway, $this->createRuntimeProvider(), $body, 'verified')->getOutcome());
      self::assertSame(PostbackOutcome::Duplicate, $processor->process($this->paymentGateway, $this->createRuntimeProvider(), $body, 'verified')->getOutcome());
      // Equivalent callback bodies also cannot create another adjustment.
      $processor->process($this->paymentGateway, $this->createRuntimeProvider(), $body . "\n", 'verified');
      $payment = $this->reloadEntity($payment);
      self::assertInstanceOf(PaymentInterface::class, $payment);
      $this->reloadOrder();
      $expected = (new Price('30', 'UAH'))->subtract(new Price($cumulative, 'UAH'));
      self::assertEquals($expected, $this->order->getTotalPrice());
      self::assertEquals($expected, $this->order->getTotalPaid());
      self::assertTrue($this->order->getBalance()->isZero());
      self::assertTrue($this->order->isPaid());
      $adjustments = $this->order->getAdjustments(['novapay_partial_refund']);
      self::assertCount(1, $adjustments);
      self::assertEquals(new Price('-' . $cumulative, 'UAH'), $adjustments[0]->getAmount());
      self::assertTrue($adjustments[0]->isLocked());
      self::assertSame('novapay_partial_refund:' . $payment->id(), $adjustments[0]->getSourceId());
      self::assertSame('6', $this->order->getItems()[0]->getQuantity());
    }
    $manager->refund($payment, $this->createRuntimeProvider());
    $body = json_encode([
      'id' => 'adjusted-refunds', 'status' => 'voided',
      'payments' => [['external_id' => (string) $this->order->id(), 'amount' => '30']],
    ], JSON_THROW_ON_ERROR);
    $processor->process($this->paymentGateway, $this->createRuntimeProvider(), $body, 'verified');
    $this->reloadOrder();
    self::assertEquals(new Price('20', 'UAH'), $this->order->getTotalPrice());
    self::assertCount(1, $this->order->getAdjustments(['novapay_partial_refund']));
    self::assertFalse($this->order->isPaid());
  }

  /**
   * Tests exact adjustments preserve existing discounts, debt and overpayment.
   */
  #[DataProvider('refundAdjustmentBalanceProvider')]
  public function testPartialRefundAdjustmentPreservesExistingBalance(string $item_price, string $discount, string $refund, string $total, string $paid, string $balance): void {
    $item = $this->order->getItems()[0];
    $item->setUnitPrice(new Price($item_price, 'UAH'));
    $item->save();
    $this->order->set('state', 'completed');
    $this->order->addAdjustment(new Adjustment([
      'type' => 'custom', 'label' => 'Existing discount',
      'amount' => new Price($discount, 'UAH'), 'locked' => TRUE,
      'source_id' => 'existing-discount',
    ]));
    $this->order->save();
    $other = $this->createCompletedPayment('other-adjustment-payment');
    $other->setAmount(new Price('5', 'UAH'))->save();
    $payment = $this->createCompletedPayment('refund-balance');
    $api = $this->createMock(NovaPayApiClientInterface::class);
    $api->expects(self::once())->method('voidPayment')->willReturn(new AcknowledgementResponse(200));
    $manager = $this->createRefundOperationManager($api);
    $manager->refundAmount($payment, $this->createRuntimeProvider(), $refund);
    $body = json_encode([
      'id' => 'refund-balance', 'status' => 'paid',
      'payments' => [['external_id' => (string) $this->order->id(), 'amount' => '30', 'refunded_amount' => $refund]],
    ], JSON_THROW_ON_ERROR);
    self::assertSame(PostbackOutcome::Applied, $this->createVerifiedPostbackProcessor()->process($this->paymentGateway, $this->createRuntimeProvider(), $body, 'verified')->getOutcome());
    $this->reloadOrder();
    self::assertEquals(new Price($total, 'UAH'), $this->order->getTotalPrice());
    self::assertEquals(new Price($paid, 'UAH'), $this->order->getTotalPaid());
    self::assertEquals(new Price($balance, 'UAH'), $this->order->getBalance());
    self::assertCount(2, $this->order->getAdjustments());
    self::assertEquals(new Price($discount, 'UAH'), $this->order->getAdjustments(['custom'])[0]->getAmount());
    $payment = $this->reloadEntity($payment);
    self::assertInstanceOf(PaymentInterface::class, $payment);
    self::assertTrue($payment->get('novapay_pending_operation')->isEmpty());
  }

  /**
   * Provides exact refund results for an unpaid and an overpaid order.
   *
   * @return iterable<string, array{string, string, string, string, string, string}>
   *   Item price, discount, refund and expected total, net paid and balance.
   */
  public static function refundAdjustmentBalanceProvider(): iterable {
    yield 'unrelated debt' => ['60', '-10', '5', '45', '30', '15'];
    yield 'overpaid discounted order' => ['30', '-25', '10', '-5', '25', '-30'];
  }

  /**
   * Tests that a non-NovaPay payment cannot introduce the refund adjustment.
   */
  public function testPartialRefundAdjustmentRequiresNovaPayGateway(): void {
    $payment = $this->createCompletedPayment('manual-refund-adjustment');
    $payment->setState('partially_refunded');
    $payment->setRefundedAmount(new Price('5', 'UAH'));
    $payment->save();
    $this->paymentGateway->setPluginId('manual');
    $this->paymentGateway->save();
    $payment = $this->reloadEntity($payment);
    self::assertInstanceOf(PaymentInterface::class, $payment);
    self::assertSame('manual', $payment->getPaymentGateway()->getPluginId());
    $this->container->get('commerce_novapay.refund_order_adjustment')->apply($this->order, $payment);
    self::assertSame([], $this->order->getAdjustments());
    self::assertEquals(new Price('30', 'UAH'), $this->order->getTotalPrice());
  }

  /**
   * Tests that a documented refund failure can be corrected or escalated.
   */
  public function testRefundErrorClearsPendingMarkerAndIsActionable(): void {
    $this->setQuantityStep('0.5');
    $payment = $this->createCompletedPayment('refund-error-session');
    $order_item_id = (int) $this->order->getItems()[0]->id();
    $api_client = $this->createMock(NovaPayApiClientInterface::class);
    $api_client->expects(self::once())
      ->method('voidPayment')
      ->willThrowException(new ApiProcessingException(400, 'RefundError'));
    $manager = $this->createRefundOperationManager($api_client);

    try {
      $manager->refund(
        $payment,
        $this->createRuntimeProvider(),
        [$order_item_id => '0.5'],
      );
      self::fail('A rejected refund must fail the form request.');
    }
    catch (PaymentGatewayException $exception) {
      self::assertStringContainsString('supplied parameters', $exception->getMessage());
      self::assertStringContainsString('support', $exception->getMessage());
    }

    $payment = $this->reloadEntity($payment);
    self::assertInstanceOf(PaymentInterface::class, $payment);
    self::assertTrue($payment->get('novapay_pending_operation')->isEmpty());
    self::assertTrue($payment->get('novapay_pending_refund')->isEmpty());
    self::assertTrue($manager->canRefund($payment));
  }

  /**
   * Tests that selecting every remaining item submits a full refund.
   */
  public function testCompleteItemSelectionRequestsFullRefund(): void {
    $payment = $this->createCompletedPayment('selected-full-refund-session');
    $order_item_id = (int) $this->order->getItems()[0]->id();
    $api_client = $this->createMock(NovaPayApiClientInterface::class);
    $api_client->expects(self::once())->method('voidPayment')
      ->with(
        self::isInstanceOf(RuntimeConfigurationProviderInterface::class),
        self::callback(static fn (VoidRequest $request): bool =>
          $request->toArray() === [
            'session_id' => 'selected-full-refund-session',
          ]),
      )
      ->willReturn(new AcknowledgementResponse(200));
    $manager = $this->createRefundOperationManager($api_client);

    $manager->refund(
      $payment,
      $this->createRuntimeProvider(),
      [$order_item_id => '1'],
    );

    $payment = $this->reloadEntity($payment);
    self::assertInstanceOf(PaymentInterface::class, $payment);
    $pending = json_decode(
      $payment->get('novapay_pending_refund')->getString(),
      TRUE,
      32,
      JSON_THROW_ON_ERROR,
    );
    self::assertIsArray($pending);
    self::assertTrue($pending['full']);
    self::assertSame(
      '30',
      $payment->get('novapay_pending_amount')->getString(),
    );
  }

  /**
   * Tests manual reconciliation of a partial refund without a postback.
   */
  public function testChecksPendingPartialRefundStatus(): void {
    $this->setQuantityStep('0.5');
    $payment = $this->createCompletedPayment('status-partial-session');
    $order_item_id = (int) $this->order->getItems()[0]->id();
    $api_client = $this->createMock(NovaPayApiClientInterface::class);
    $api_client->expects(self::once())->method('voidPayment')
      ->willReturn(new AcknowledgementResponse(200));
    $api_client->expects(self::once())->method('getStatus')
      ->willReturn(SessionStatusResponse::fromArray([
        'id' => 'status-partial-session',
        'status' => 'paid',
        'client_phone' => '+380501234567',
        'operations' => [[
          'transaction_id' => 'operation-id',
          'refunded_amount' => '15.00',
        ]],
      ]));
    $manager = $this->createRefundOperationManager($api_client);

    $manager->refund(
      $payment,
      $this->createRuntimeProvider(),
      [$order_item_id => '0.5'],
    );
    $payment = $this->reloadEntity($payment);
    self::assertInstanceOf(PaymentInterface::class, $payment);
    self::assertTrue($manager->canCheckStatus($payment));
    $operations = $this->paymentGateway->getPlugin()
      ->buildPaymentOperations($payment);
    self::assertArrayHasKey('check_refund_status', $operations);
    self::assertTrue($operations['check_refund_status']['access']);

    $result = $manager->checkStatus(
      $payment,
      $this->createRuntimeProvider(),
    );

    self::assertSame(RefundStatusCheckResult::Confirmed, $result);
    $payment = $this->reloadEntity($payment);
    self::assertInstanceOf(PaymentInterface::class, $payment);
    self::assertSame('partially_refunded', $payment->getState()->getId());
    self::assertEquals(new Price('15', 'UAH'), $payment->getRefundedAmount());
    self::assertFalse($manager->canCheckStatus($payment));
    $operations = $this->paymentGateway->getPlugin()
      ->buildPaymentOperations($payment);
    self::assertFalse($operations['check_refund_status']['access']);
    self::assertSame(1, $this->countRefundLedgerRows($payment));
    $this->reloadOrder();
    self::assertEquals(new Price('15', 'UAH'), $this->order->getTotalPrice());
    self::assertEquals(new Price('15', 'UAH'), $this->order->getTotalPaid());
    self::assertTrue($this->order->getBalance()->isZero());
    self::assertTrue($this->order->isPaid());
    self::assertCount(1, $this->order->getAdjustments(['novapay_partial_refund']));
  }

  /**
   * Tests that an inconclusive status never clears a pending refund.
   */
  public function testKeepsRefundPendingWithoutRemoteEvidence(): void {
    $this->setQuantityStep('0.5');
    $payment = $this->createCompletedPayment('status-pending-session');
    $order_item_id = (int) $this->order->getItems()[0]->id();
    $api_client = $this->createMock(NovaPayApiClientInterface::class);
    $api_client->method('voidPayment')
      ->willReturn(new AcknowledgementResponse(200));
    $api_client->method('getStatus')
      ->willReturn(SessionStatusResponse::fromArray([
        'id' => 'status-pending-session',
        'status' => 'paid',
        'operations' => [[
          'transaction_id' => 'operation-id',
          'refunded_amount' => '0',
        ]],
      ]));
    $manager = $this->createRefundOperationManager($api_client);
    $manager->refund(
      $payment,
      $this->createRuntimeProvider(),
      [$order_item_id => '0.5'],
    );
    $payment = $this->reloadEntity($payment);
    self::assertInstanceOf(PaymentInterface::class, $payment);

    $result = $manager->checkStatus(
      $payment,
      $this->createRuntimeProvider(),
    );

    self::assertSame(RefundStatusCheckResult::Pending, $result);
    $payment = $this->reloadEntity($payment);
    self::assertInstanceOf(PaymentInterface::class, $payment);
    self::assertTrue($manager->canCheckStatus($payment));
    self::assertEquals(new Price('0', 'UAH'), $payment->getRefundedAmount());
    self::assertSame(0, $this->countRefundLedgerRows($payment));
  }

  /**
   * Tests general status recovery cannot bypass pending refund evidence.
   */
  public function testGeneralStatusCheckCannotFinalizePendingPartialRefund(): void {
    $payment = $this->createCompletedPayment('pending-refund-status-guard');
    $refund_client = $this->createMock(NovaPayApiClientInterface::class);
    $refund_client->expects(self::once())->method('voidPayment')
      ->willReturn(new AcknowledgementResponse(200));
    $refund_manager = $this->createRefundOperationManager($refund_client);
    $refund_manager->refundAmount($payment, $this->createRuntimeProvider(), '5');
    $payment = $this->reloadEntity($payment);
    self::assertInstanceOf(PaymentInterface::class, $payment);
    self::assertTrue($refund_manager->canCheckStatus($payment));
    $pending = $payment->get('novapay_pending_refund')->getString();

    // Even a terminal session status must go through refund reconciliation.
    $status_client = $this->createMock(NovaPayApiClientInterface::class);
    $status_client->expects(self::never())->method('getStatus')
      ->willReturn(SessionStatusResponse::fromArray([
        'id' => 'pending-refund-status-guard',
        'status' => 'voided',
      ]));
    $status_client->expects(self::never())->method('voidPayment');
    $manager = $this->createPaymentStatusCheckManager($status_client);
    self::assertFalse($manager->canCheckStatus($payment));
    try {
      $manager->checkStatus($payment, $this->createRuntimeProvider());
      self::fail('General status recovery must not bypass refund evidence.');
    }
    catch (InvalidRequestException) {
      $payment = $this->reloadEntity($payment);
      self::assertInstanceOf(PaymentInterface::class, $payment);
      self::assertSame('completed', $payment->getState()->getId());
      self::assertEquals(new Price('0', 'UAH'), $payment->getRefundedAmount());
      self::assertEquals(new Price('30', 'UAH'), $payment->getBalance());
      self::assertSame('refund', $payment->get('novapay_pending_operation')->getString());
      self::assertSame($pending, $payment->get('novapay_pending_refund')->getString());
      self::assertSame(0, $this->countRefundLedgerRows($payment));
      self::assertTrue($refund_manager->canCheckStatus($payment));
    }
  }

  /**
   * Tests manual reconciliation of a full refund without a postback.
   */
  public function testChecksPendingFullRefundStatus(): void {
    $payment = $this->createCompletedPayment('status-full-session');
    $api_client = $this->createMock(NovaPayApiClientInterface::class);
    $api_client->method('voidPayment')
      ->willReturn(new AcknowledgementResponse(200));
    $api_client->method('getStatus')
      ->willReturn(SessionStatusResponse::fromArray([
        'id' => 'status-full-session',
        'status' => 'paid',
        'operations' => [[
          'transaction_id' => 'operation-id',
          'refunded_amount' => '30',
        ]],
      ]));
    $manager = $this->createRefundOperationManager($api_client);
    $manager->refund($payment, $this->createRuntimeProvider());
    $payment = $this->reloadEntity($payment);
    self::assertInstanceOf(PaymentInterface::class, $payment);

    $result = $manager->checkStatus(
      $payment,
      $this->createRuntimeProvider(),
    );

    self::assertSame(RefundStatusCheckResult::Confirmed, $result);
    $payment = $this->reloadEntity($payment);
    self::assertInstanceOf(PaymentInterface::class, $payment);
    self::assertSame('refunded', $payment->getState()->getId());
    self::assertEquals(new Price('30', 'UAH'), $payment->getRefundedAmount());
    self::assertSame(1, $this->countRefundLedgerRows($payment));
  }

  /**
   * Tests atomic rollback when a full-refund workflow save fails.
   */
  public function testFullRefundStatusCheckRollsBackFailedTransition(): void {
    $payment = $this->createCompletedPayment('status-rollback-session');
    $api_client = $this->createMock(NovaPayApiClientInterface::class);
    $api_client->method('voidPayment')
      ->willReturn(new AcknowledgementResponse(200));
    $api_client->method('getStatus')
      ->willReturn(SessionStatusResponse::fromArray([
        'id' => 'status-rollback-session',
        'status' => 'voided',
      ]));
    $manager = $this->createRefundOperationManager($api_client);
    $manager->refund($payment, $this->createRuntimeProvider());
    $payment = $this->reloadEntity($payment);
    self::assertInstanceOf(PaymentInterface::class, $payment);

    $database = $this->container->get('database');
    $schema = rtrim($database->getPrefix(), '.');
    self::assertMatchesRegularExpression('/^[A-Za-z0-9_]+$/D', $schema);
    $trigger = sprintf(
      '"%s"."commerce_novapay_test_refund_failure"',
      $schema,
    );
    $database->query(
      "CREATE TRIGGER $trigger
      BEFORE UPDATE ON commerce_payment
      WHEN NEW.state = 'refunded'
      BEGIN
        SELECT RAISE(FAIL, 'forced refund transition failure');
      END",
      [],
      ['allow_delimiter_in_query' => TRUE],
    );
    try {
      $manager->checkStatus($payment, $this->createRuntimeProvider());
      self::fail('A failed workflow save must abort refund reconciliation.');
    }
    catch (PaymentGatewayException $exception) {
      self::assertStringContainsString(
        'atomically',
        $exception->getMessage(),
      );
    }
    finally {
      $database->query("DROP TRIGGER $trigger");
    }

    $payment = $this->reloadEntity($payment);
    self::assertInstanceOf(PaymentInterface::class, $payment);
    self::assertSame('completed', $payment->getState()->getId());
    self::assertEquals(new Price('0', 'UAH'), $payment->getRefundedAmount());
    self::assertSame(
      'refund',
      $payment->get('novapay_pending_operation')->getString(),
    );
    self::assertFalse($payment->get('novapay_pending_refund')->isEmpty());
    self::assertSame(0, $this->countRefundLedgerRows($payment));
  }

  /**
   * Tests refund quantities against the order item widget configuration.
   */
  public function testRefundQuantityUsesOrderItemTypeStep(): void {
    $payment = $this->createCompletedPayment('quantity-step-session');
    $order_item_id = (int) $this->order->getItems()[0]->id();
    $api_client = $this->createMock(NovaPayApiClientInterface::class);
    $api_client->expects(self::once())->method('voidPayment')
      ->willReturn(new AcknowledgementResponse(200));
    $manager = $this->createRefundOperationManager($api_client);

    $items = $manager->getRefundableItems($payment);
    self::assertCount(1, $items);
    self::assertSame('1', $items[0]->getQuantityStep());
    $plugin = $this->paymentGateway->getPlugin();
    $plugin_form = new NovaPayRefundForm();
    $plugin_form->setEntity($payment);
    $plugin_form->setPlugin($plugin);
    $form = $plugin_form->buildConfigurationForm([], new FormState());
    self::assertSame(
      '1',
      $form['items'][$order_item_id]['quantity']['#step'],
    );
    self::assertSame(
      '',
      $form['items'][$order_item_id]['quantity']['#default_value'],
    );

    try {
      $manager->refund(
        $payment,
        $this->createRuntimeProvider(),
        [$order_item_id => '0.5'],
      );
      self::fail('A fractional refund must be rejected for a step of 1.');
    }
    catch (InvalidRequestException $exception) {
      self::assertStringContainsString('quantity step', $exception->getMessage());
    }

    $this->setQuantityStep('0.5');
    $items = $manager->getRefundableItems($payment);
    self::assertSame('0.5', $items[0]->getQuantityStep());
    $form = $plugin_form->buildConfigurationForm([], new FormState());
    self::assertSame(
      '0.5',
      $form['items'][$order_item_id]['quantity']['#step'],
    );
    $manager->refund(
      $payment,
      $this->createRuntimeProvider(),
      [$order_item_id => '0.5'],
    );
  }

  /**
   * Tests that an untrusted browser return cannot create or complete payment.
   */
  public function testBrowserReturnDoesNotChangePaymentState(): void {
    $payment_storage = $this->container
      ->get('entity_type.manager')
      ->getStorage('commerce_payment');
    $query = $payment_storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('order_id', $this->order->id());
    self::assertSame(0, (int) $query->count()->execute());

    $plugin = $this->paymentGateway->getPlugin();
    self::assertInstanceOf(OffsitePaymentGatewayInterface::class, $plugin);
    $plugin->onReturn(
      $this->order,
      new Request(['status' => 'paid', 'session_id' => 'untrusted']),
    );

    self::assertSame(0, (int) $query->count()->execute());
    self::assertEquals(new Price('0', 'UAH'), $this->order->getTotalPaid());
  }

  /**
   * Tests replaying a previously ignored expiry after a failed V1 payment.
   */
  public function testFailedPaymentExpiresOnPostbackReplay(): void {
    $session = 'failed-then-expired';
    $payment = $this->createCompletedPayment($session);
    $payment->setState('pending')->setRemoteState('created')->save();
    $processor = $this->createVerifiedPostbackProcessor();
    $runtime = $this->createRuntimeProvider();
    $body = fn (string $status): string => json_encode([
      'external_id' => (string) $this->order->id(),
      'id' => $session,
      'status' => $status,
      'amount' => 30,
    ], JSON_THROW_ON_ERROR);
    $failed = $processor->process($this->paymentGateway, $runtime, $body('failed'), 'verified');
    self::assertSame(PostbackOutcome::Applied, $failed->getOutcome());

    $expired_body = $body('expired');
    $this->container->get('commerce_novapay.postback.event_repository')->processOnce(
      hash('sha256', $expired_body),
      $session,
      (string) $this->paymentGateway->id(),
      NovaPayStatus::Expired,
      static fn (): PostbackOutcome => PostbackOutcome::Ignored,
    );
    $expired = $processor->process($this->paymentGateway, $runtime, $expired_body, 'verified');
    self::assertSame(PostbackOutcome::Applied, $expired->getOutcome());
    $payment = $this->reloadEntity($payment);
    self::assertInstanceOf(PaymentInterface::class, $payment);
    self::assertSame('expired', $payment->getState()->getId());
    self::assertSame('expired', $payment->getRemoteState());
    $duplicate = $processor->process($this->paymentGateway, $runtime, $expired_body, 'verified');
    self::assertSame(PostbackOutcome::Duplicate, $duplicate->getOutcome());
  }

  /**
   * Tests real workflow persistence for verified hold postback statuses.
   */
  public function testPostbackStatusMappingPersistsWorkflowTransitions(): void {
    $this->order->set('state', 'completed');
    $this->order->save();
    $payment_storage = $this->container
      ->get('entity_type.manager')
      ->getStorage('commerce_payment');
    /** @var \Drupal\commerce_payment\Entity\PaymentInterface $payment */
    $payment = $payment_storage->create([
      'payment_gateway' => $this->paymentGateway,
      'order_id' => $this->order->id(),
      'amount' => new Price('30', 'UAH'),
      'state' => 'pending',
      'remote_id' => 'postback-session',
      'remote_state' => 'created',
    ]);
    $payment->save();
    $mapper = $this->container->get(
      'commerce_novapay.postback.status_mapper',
    );
    self::assertInstanceOf(PaymentStatusMapperInterface::class, $mapper);

    $mapper->apply($payment, NovaPayStatus::Holded);
    $payment = $this->reloadEntity($payment);
    self::assertInstanceOf(PaymentInterface::class, $payment);
    self::assertSame('authorization', $payment->getState()->getId());
    self::assertSame('holded', $payment->getRemoteState());
    $this->reloadOrder();
    self::assertEquals(new Price('0', 'UAH'), $this->order->getTotalPaid());
    self::assertFalse($this->order->isPaid());

    $mapper->apply($payment, NovaPayStatus::Paid);
    $payment = $this->reloadEntity($payment);
    self::assertInstanceOf(PaymentInterface::class, $payment);
    self::assertSame('completed', $payment->getState()->getId());
    self::assertSame('paid', $payment->getRemoteState());
    $this->reloadOrder();
    self::assertEquals(new Price('30', 'UAH'), $this->order->getTotalPaid());
    self::assertTrue($this->order->isPaid());
    self::assertEquals(new Price('0', 'UAH'), $this->order->getBalance());
    self::assertSame('completed', $this->order->getState()->getId());

    $mapper->apply($payment, NovaPayStatus::Voided);
    $this->reloadOrder();
    self::assertEquals(new Price('0', 'UAH'), $this->order->getTotalPaid());
    self::assertEquals(new Price('30', 'UAH'), $this->order->getBalance());
    self::assertFalse($this->order->isPaid());
  }

  /**
   * Tests capture operation persistence, duplicates, and refund target.
   */
  public function testCaptureCallbackSelectsNewRefundOperation(): void {
    $payment = $this->createAuthorizationPayment('capture-refund-session');
    $client = $this->createMock(NovaPayApiClientInterface::class);
    $client->expects(self::once())->method('completeHold')
      ->willReturn(new AcknowledgementResponse(200));
    $this->createAuthorizationOperationManager($client)->capture(
      $payment, $this->createRuntimeProvider(), new Price('10', 'UAH'),
    );
    $processor = $this->createVerifiedPostbackProcessor();
    $body = $this->createCapturePostback('capture-refund-session', '10');
    self::assertSame(PostbackOutcome::Applied, $processor->process(
      $this->paymentGateway, $this->createRuntimeProvider(), $body, 'verified',
    )->getOutcome());
    $payment = $this->reloadEntity($payment);
    self::assertInstanceOf(PaymentInterface::class, $payment);
    self::assertSame('capture-operation-id', $payment->get('novapay_operation_id')->getString());
    self::assertEquals(new Price('10', 'UAH'), $payment->getAmount());
    self::assertSame(PostbackOutcome::Duplicate, $processor->process(
      $this->paymentGateway, $this->createRuntimeProvider(), $body, 'verified',
    )->getOutcome());

    $this->reloadOrder();
    self::assertEquals(new Price('10', 'UAH'), $this->order->getTotalPrice());
    self::assertTrue($this->order->isPaid());
    self::assertCount(1, $this->order->getAdjustments());
    $adjustment = $this->order->getAdjustments()[0];
    self::assertEquals(new Price('-20', 'UAH'), $adjustment->getAmount());
    self::assertTrue($adjustment->isLocked());
    self::assertFalse($adjustment->isIncluded());
    self::assertSame('novapay_partial_capture:' . $payment->id(), $adjustment->getSourceId());
    self::assertSame(PostbackOutcome::Ignored, $processor->process(
      $this->paymentGateway, $this->createRuntimeProvider(), $body . "\n", 'verified',
    )->getOutcome());
    $this->reloadOrder();
    self::assertCount(1, $this->order->getAdjustments());
    self::assertEquals(new Price('30', 'UAH'), $this->order->getItems()[0]->getUnitPrice());
    self::assertSame('1', $this->order->getItems()[0]->getQuantity());

    $refund_client = $this->createMock(NovaPayApiClientInterface::class);
    $refund_client->expects(self::once())->method('voidPayment')
      ->with(self::anything(), self::callback(static fn (VoidRequest $request): bool =>
        $request->toArray() === ['session_id' => 'capture-refund-session']))
      ->willReturn(new AcknowledgementResponse(200));
    $refund_client->expects(self::once())->method('getStatus')
      ->willReturn(SessionStatusResponse::fromArray([
        'id' => 'capture-refund-session',
        'status' => 'voided',
      ]));
    $refund_manager = $this->createRefundOperationManager($refund_client);
    self::assertCount(1, $refund_manager->getRefundableItems($payment));
    $form = new NovaPayRefundForm();
    $form->setEntity($payment);
    $form->setPlugin($this->paymentGateway->getPlugin());
    $build = $form->buildConfigurationForm(['#parents' => ['payment']], new FormState());
    self::assertArrayHasKey('items', $build);
    self::assertSame('value', $build['refund_mode']['#type']);
    $refund_manager->refund($payment, $this->createRuntimeProvider());
    $payment = $this->reloadEntity($payment);
    self::assertInstanceOf(PaymentInterface::class, $payment);
    self::assertSame('10', $payment->get('novapay_pending_amount')->getString());
    self::assertSame('completed', $payment->getState()->getId());
    self::assertTrue($payment->getRefundedAmount()->isZero());
    self::assertSame(RefundStatusCheckResult::Confirmed, $refund_manager->checkStatus($payment, $this->createRuntimeProvider()));
    $payment = $this->reloadEntity($payment);
    self::assertInstanceOf(PaymentInterface::class, $payment);
    self::assertEquals(new Price('10', 'UAH'), $payment->getRefundedAmount());
    $this->reloadOrder();
    self::assertEquals(new Price('10', 'UAH'), $this->order->getTotalPrice());
    self::assertEquals(new Price('0', 'UAH'), $this->order->getTotalPaid());
    self::assertCount(1, $this->order->getAdjustments());
    self::assertFalse($this->order->isPaid());

  }

  /**
   * Tests that another gateway cannot receive the NovaPay capture adjustment.
   */
  public function testPartialCaptureAdjustmentRequiresNovaPayGateway(): void {
    $payment = $this->createAuthorizationPayment('other-gateway-capture');
    $payment->set('novapay_pending_operation', 'capture');
    $payment->set('novapay_pending_amount', '10');
    $payment->save();
    $this->paymentGateway->setPluginId('manual');
    $this->paymentGateway->save();
    $payment = $this->reloadEntity($payment);
    self::assertInstanceOf(PaymentInterface::class, $payment);
    self::assertSame('manual', $payment->getPaymentGateway()->getPluginId());
    $mapper = $this->container->get('commerce_novapay.postback.status_mapper');
    self::assertTrue($mapper->apply($payment, NovaPayStatus::HoldConfirmed));
    $this->reloadOrder();
    self::assertSame([], $this->order->getAdjustments());
    self::assertEquals(new Price('30', 'UAH'), $this->order->getTotalPrice());
    self::assertEquals(new Price('20', 'UAH'), $this->order->getBalance());
    self::assertFalse($this->order->isPaid());
  }

  /**
   * Tests partial capture refunds, cumulative limits and order adjustments.
   */
  #[DataProvider('partialCaptureRefundProvider')]
  public function testPartialCaptureAllowsItemRefunds(bool $legacy): void {
    $this->setQuantityStep('0.1');
    $payment = $this->createAuthorizationPayment('partial-capture-item-refund');
    $payment->set('novapay_pending_operation', 'capture');
    $payment->set('novapay_pending_amount', '10');
    $payment->save();
    $this->createVerifiedPostbackProcessor()->process(
      $this->paymentGateway, $this->createRuntimeProvider(),
      $this->createCapturePostback('partial-capture-item-refund', '10'), 'verified',
    );
    $payment = $this->reloadEntity($payment);
    self::assertInstanceOf(PaymentInterface::class, $payment);
    if ($legacy) {
      $order = $payment->getOrder();
      $order->setData('commerce_novapay_partial_captures', [])->save();
    }
    $item_id = (int) $this->order->getItems()[0]->id();
    $requests = [];
    $client = $this->createMock(NovaPayApiClientInterface::class);
    $client->method('voidPayment')->willReturnCallback(
      static function (RuntimeConfigurationProviderInterface $gateway, VoidRequest $request) use (&$requests): AcknowledgementResponse {
        $requests[] = $request->toArray();
        return new AcknowledgementResponse(200);
      },
    );
    $manager = $this->createRefundOperationManager($client);
    $manager->refund($payment, $this->createRuntimeProvider(), [$item_id => '0.1']);
    self::assertSame([[
      'session_id' => 'partial-capture-item-refund',
      'operations' => [['id' => 'capture-operation-id', 'refund_amount' => '3']],
    ]], $requests);
    $payment = $this->reloadEntity($payment);
    self::assertInstanceOf(PaymentInterface::class, $payment);
    self::assertTrue($payment->getRefundedAmount()->isZero());
    self::assertSame(0, $this->countRefundLedgerRows($payment));
    $this->reloadOrder();
    self::assertEquals(new Price('10', 'UAH'), $this->order->getTotalPrice());

    $event = NormalizedPostbackEvent::fromValues(
      'partial-capture-item-refund', 'paid',
      [(string) $this->order->id()], ['3'],
    );
    $manager->confirm($payment, $event, hash('sha256', 'captured-partial-refund'));
    $payment = $this->reloadEntity($payment);
    self::assertInstanceOf(PaymentInterface::class, $payment);
    self::assertSame('partially_refunded', $payment->getState()->getId());
    self::assertEquals(new Price('3', 'UAH'), $payment->getRefundedAmount());
    self::assertEquals(new Price('7', 'UAH'), $payment->getBalance());
    self::assertSame(1, $this->countRefundLedgerRows($payment));
    self::assertSame('0.9', $manager->getRefundableItems($payment)[0]->getAvailableQuantity());
    $this->reloadOrder();
    self::assertEquals(new Price('7', 'UAH'), $this->order->getTotalPrice());
    self::assertTrue($this->order->isPaid());
    self::assertCount(2, $this->order->getAdjustments());

    try {
      $manager->refund($payment, $this->createRuntimeProvider(), [$item_id => '0.3']);
      self::fail('Item refund cannot exceed the remaining captured balance.');
    }
    catch (InvalidRequestException $exception) {
      self::assertStringContainsString('paid balance', $exception->getMessage());
    }
    self::assertCount(1, $requests);
    $manager->refund($payment, $this->createRuntimeProvider(), [$item_id => '0.9']);
    self::assertSame([
      [
        'session_id' => 'partial-capture-item-refund',
        'operations' => [['id' => 'capture-operation-id', 'refund_amount' => '3']],
      ],
      ['session_id' => 'partial-capture-item-refund'],
    ], $requests);
    $payment = $this->reloadEntity($payment);
    self::assertInstanceOf(PaymentInterface::class, $payment);
    self::assertSame('7', $payment->get('novapay_pending_amount')->getString());
    $manager->confirm($payment, NormalizedPostbackEvent::fromValues(
      'partial-capture-item-refund', 'voided', [(string) $this->order->id()],
    ), hash('sha256', 'captured-final-refund'));
    (new PaymentStatusMapper(
      $this->container->get('commerce_payment.order_updater'),
    ))->apply($payment, NovaPayStatus::Voided);
    $payment = $this->reloadEntity($payment);
    self::assertInstanceOf(PaymentInterface::class, $payment);
    self::assertSame('refunded', $payment->getState()->getId());
    self::assertEquals(new Price('10', 'UAH'), $payment->getRefundedAmount());
  }

  /**
   * Provides captured payments with and without recorded capture provenance.
   *
   * @return array<string, array{bool}>
   *   Capture provenance scenarios.
   */
  public static function partialCaptureRefundProvider(): array {
    return ['recorded capture' => [FALSE], 'legacy capture' => [TRUE]];
  }

  /**
   * Tests full captures and direct payments keep item refunds available.
   */
  public function testFullCaptureAndDirectRefundAvailability(): void {
    $full = $this->createAuthorizationPayment('full-capture-refund');
    $full->set('novapay_pending_operation', 'capture');
    $full->set('novapay_pending_amount', '30');
    $full->save();
    $this->createVerifiedPostbackProcessor()->process(
      $this->paymentGateway, $this->createRuntimeProvider(),
      $this->createCapturePostback('full-capture-refund', '30'), 'verified',
    );
    $full = $this->reloadEntity($full);
    self::assertInstanceOf(PaymentInterface::class, $full);
    $manager = $this->container->get('commerce_novapay.refund_operation_manager');
    self::assertCount(1, $manager->getRefundableItems($full));
    self::assertFalse($full->getOrder()->getData('commerce_novapay_partial_captures')[(string) $full->id()]);
    $direct = $this->createCompletedPayment('direct-small-payment');
    $direct->setAmount(new Price('10', 'UAH'))->save();
    self::assertNull($direct->getAuthorizedTime());
    self::assertCount(1, $manager->getRefundableItems($direct));
    // Legacy partial captures also allow items within the captured balance.
    $legacy = $this->createAuthorizationPayment('legacy-partial-capture');
    $legacy->setState('completed');
    $legacy->setAmount(new Price('10', 'UAH'))->save();
    self::assertCount(1, $manager->getRefundableItems($legacy));
    $this->reloadOrder();
    self::assertSame([], $this->order->getAdjustments());
  }

  /**
   * Tests read-only recovery persists the operation used for partial refunds.
   */
  public function testCaptureStatusRecoversNewRefundOperation(): void {
    $payment = $this->createAuthorizationPayment('capture-status-recovery');
    $payment->set('novapay_pending_operation', 'capture');
    $payment->set('novapay_pending_amount', '10');
    $payment->save();
    $client = $this->createMock(NovaPayApiClientInterface::class);
    $client->expects(self::once())->method('getStatus')
      ->willReturn(SessionStatusResponse::fromArray([
        'id' => 'capture-status-recovery',
        'status' => 'hold_confirmed',
        'operations' => [['transaction_id' => 'capture-status-id', 'status' => 'paid']],
      ]));
    $client->expects(self::never())->method('completeHold');
    $client->expects(self::never())->method('voidPayment');
    self::assertSame(PaymentStatusCheckResult::Reconciled,
      $this->createPaymentStatusCheckManager($client)->checkStatus($payment, $this->createRuntimeProvider()));
    $payment = $this->reloadEntity($payment);
    self::assertInstanceOf(PaymentInterface::class, $payment);
    self::assertSame('completed', $payment->getState()->getId());
    self::assertSame('capture-status-id', $payment->get('novapay_operation_id')->getString());
    self::assertEquals(new Price('10', 'UAH'), $payment->getAmount());
  }

  /**
   * Tests uncertain capture evidence cannot finalize a pending partial capture.
   */
  public function testCaptureStatusRejectsMissingOrAmbiguousOperation(): void {
    foreach ([[], [['transaction_id' => 'operation-id', 'status' => 'paid']], [
      ['transaction_id' => 'capture-a', 'status' => 'paid'],
      ['transaction_id' => 'capture-b', 'status' => 'paid'],
    ]] as $index => $operations) {
      $session = 'capture-ambiguous-' . $index;
      $payment = $this->createAuthorizationPayment($session);
      $payment->set('novapay_pending_operation', 'capture');
      $payment->set('novapay_pending_amount', '10');
      $payment->save();
      $client = $this->createMock(NovaPayApiClientInterface::class);
      $client->method('getStatus')->willReturn(SessionStatusResponse::fromArray([
        'id' => $session,
        'status' => 'paid',
        'operations' => $operations,
      ]));
      $client->expects(self::never())->method('voidPayment');
      try {
        $this->createPaymentStatusCheckManager($client)->checkStatus($payment, $this->createRuntimeProvider());
        self::fail('Partial capture needs an unambiguous new operation ID.');
      }
      catch (\UnexpectedValueException | ApiTransportException) {
        $payment = $this->reloadEntity($payment);
        self::assertInstanceOf(PaymentInterface::class, $payment);
        self::assertSame('authorization', $payment->getState()->getId());
        self::assertSame('capture', $payment->get('novapay_pending_operation')->getString());
        self::assertEquals(new Price('30', 'UAH'), $payment->getAmount());
        self::assertSame('operation-id', $payment->get('novapay_operation_id')->getString());
      }
    }
  }

  /**
   * Tests missing capture identity rolls back both payment and event claim.
   */
  public function testCaptureCallbackWithoutOperationRemainsPending(): void {
    $payment = $this->createAuthorizationPayment('capture-no-operation');
    $payment->set('novapay_pending_operation', 'capture');
    $payment->set('novapay_pending_amount', '10');
    $payment->save();
    $body = json_encode([
      'id' => 'capture-no-operation',
      'status' => 'paid',
      'payments' => [['external_id' => 'not-an-operation-id', 'amount' => '10']],
    ], JSON_THROW_ON_ERROR);
    $client = $this->createMock(NovaPayApiClientInterface::class);
    $client->expects(self::once())->method('getStatus')->willReturn(SessionStatusResponse::fromArray([
      'id' => 'capture-no-operation', 'status' => 'paid', 'operations' => [],
    ]));
    try {
      $this->createVerifiedPostbackProcessor($client)->process(
        $this->paymentGateway, $this->createRuntimeProvider(), $body, 'verified',
      );
      self::fail('Missing operation evidence must not finalize a partial capture.');
    }
    catch (\UnexpectedValueException) {
      $payment = $this->reloadEntity($payment);
      self::assertInstanceOf(PaymentInterface::class, $payment);
      self::assertSame('authorization', $payment->getState()->getId());
      self::assertSame('capture', $payment->get('novapay_pending_operation')->getString());
      self::assertSame('operation-id', $payment->get('novapay_operation_id')->getString());
      self::assertEquals(new Price('30', 'UAH'), $payment->getAmount());
      self::assertSame(0, (int) $this->container->get('database')
        ->select('commerce_novapay_postback_event', 'event')->countQuery()
        ->execute()->fetchField());
    }
  }

  /**
   * Tests one preliminary status lookup repairs a signed capture callback.
   */
  public function testCaptureCallbackResolvesOperationBeforeApplying(): void {
    $payment = $this->createAuthorizationPayment('capture-lookup');
    $payment->set('novapay_pending_operation', 'capture');
    $payment->set('novapay_pending_amount', '10');
    $payment->save();
    $body = json_encode([
      'id' => 'capture-lookup', 'status' => 'paid',
      'payments' => [['external_id' => 'not-an-operation', 'amount' => '10']],
    ], JSON_THROW_ON_ERROR);
    $client = $this->createMock(NovaPayApiClientInterface::class);
    $client->expects(self::once())->method('getStatus')->willReturn(SessionStatusResponse::fromArray([
      'id' => 'capture-lookup', 'status' => 'paid',
      'operations' => [['transaction_id' => 'resolved-capture-id', 'status' => 'paid']],
    ]));
    $client->expects(self::never())->method('completeHold');
    $client->expects(self::never())->method('voidPayment');
    $processor = $this->createVerifiedPostbackProcessor($client);
    $result = $processor->process($this->paymentGateway, $this->createRuntimeProvider(), $body, 'verified');
    self::assertSame('applied', $result->getOutcome()->value);
    $payment = $this->reloadEntity($payment);
    self::assertInstanceOf(PaymentInterface::class, $payment);
    self::assertSame('completed', $payment->getState()->getId());
    self::assertSame('resolved-capture-id', $payment->get('novapay_operation_id')->getString());
    self::assertEquals(new Price('10', 'UAH'), $payment->getAmount());
    self::assertTrue($payment->get('novapay_pending_operation')->isEmpty());
    $again = $processor->process($this->paymentGateway, $this->createRuntimeProvider(), $body, 'verified');
    self::assertSame('duplicate', $again->getOutcome()->value);
  }

  /**
   * Tests stale, ambiguous, mismatched and failed lookups keep capture pending.
   */
  public function testCapturePreliminaryStatusRejectsUntrustedEvidence(): void {
    foreach (['stale', 'ambiguous', 'session', 'status', 'transport'] as $case) {
      $session = 'lookup-' . $case;
      $payment = $this->createAuthorizationPayment($session);
      $payment->set('novapay_pending_operation', 'capture');
      $payment->set('novapay_pending_amount', '10');
      $payment->save();
      $body = json_encode([
        'id' => $session, 'status' => 'paid',
        'payments' => [['external_id' => 'order-reference', 'amount' => '10']],
      ], JSON_THROW_ON_ERROR);
      $client = $this->createMock(NovaPayApiClientInterface::class);
      $client->expects(self::never())->method('completeHold');
      $client->expects(self::never())->method('voidPayment');
      if ($case === 'transport') {
        $client->expects(self::once())->method('getStatus')->willThrowException(ApiTransportException::requestFailed());
      }
      else {
        $operations = $case === 'ambiguous'
          ? [['transaction_id' => 'new-a'], ['transaction_id' => 'new-b']]
          : [['transaction_id' => $case === 'stale' ? 'operation-id' : 'new-id']];
        $client->expects(self::once())->method('getStatus')->willReturn(SessionStatusResponse::fromArray([
          'id' => $case === 'session' ? 'different-session' : $session,
          'status' => $case === 'status' ? 'holded' : 'paid',
          'operations' => $operations,
        ]));
      }
      try {
        $this->createVerifiedPostbackProcessor($client)->process($this->paymentGateway, $this->createRuntimeProvider(), $body, 'verified');
        self::fail('Unsafe lookup evidence must not confirm the capture: ' . $case);
      }
      catch (\UnexpectedValueException | ApiTransportException) {
        $payment = $this->reloadEntity($payment);
        self::assertInstanceOf(PaymentInterface::class, $payment);
        self::assertSame('authorization', $payment->getState()->getId());
        self::assertSame('operation-id', $payment->get('novapay_operation_id')->getString());
        self::assertEquals(new Price('30', 'UAH'), $payment->getAmount());
        self::assertSame('capture', $payment->get('novapay_pending_operation')->getString());
      }
    }
  }

  /**
   * Tests a late callback cannot change identity without a pending capture.
   */
  public function testLateCaptureCallbackPreservesActiveOperation(): void {
    $payment = $this->createCompletedPayment('capture-conflict');
    $payment->set('novapay_operation_id', 'confirmed-capture-id');
    $payment->save();
    $this->createVerifiedPostbackProcessor()->process(
      $this->paymentGateway, $this->createRuntimeProvider(),
      $this->createCapturePostback('capture-conflict', '30'), 'verified',
    );
    $payment = $this->reloadEntity($payment);
    self::assertInstanceOf(PaymentInterface::class, $payment);
    self::assertSame('confirmed-capture-id', $payment->get('novapay_operation_id')->getString());
    self::assertSame('completed', $payment->getState()->getId());
  }

  /**
   * Tests that existing discounts cap the released-authorization adjustment.
   */
  #[DataProvider('captureAdjustmentDiscountProvider')]
  public function testPartialCaptureAdjustmentRespectsDiscounts(string $discount, string $expected_total, int $expected_count): void {
    $this->order->set('state', 'completed');
    $this->order->addAdjustment(new Adjustment([
      'type' => 'custom',
      'label' => 'Existing discount',
      'amount' => new Price($discount, 'UAH'),
      'source_id' => 'existing-discount',
    ]));
    $this->order->save();
    $payment = $this->createAuthorizationPayment('discount-capture');
    $payment->set('novapay_pending_operation', 'capture');
    $payment->set('novapay_pending_amount', '10');
    $payment->save();
    $this->container->get('commerce_novapay.postback.status_mapper')->apply($payment, NovaPayStatus::HoldConfirmed);
    $this->reloadOrder();
    self::assertEquals(new Price($expected_total, 'UAH'), $this->order->getTotalPrice());
    self::assertEquals(new Price('10', 'UAH'), $this->order->getTotalPaid());
    self::assertTrue($this->order->isPaid());
    self::assertCount($expected_count, $this->order->getAdjustments());
    self::assertEquals(new Price('30', 'UAH'), $this->order->getItems()[0]->getUnitPrice());
  }

  /**
   * Provides discounts, final order totals and expected adjustment counts.
   *
   * @return iterable<string, array{string, string, int}>
   *   Existing discount and expected outcome.
   */
  public static function captureAdjustmentDiscountProvider(): iterable {
    yield 'cap by unpaid balance' => ['-5', '10', 2];
    yield 'already settled' => ['-20', '10', 1];
    yield 'already overpaid' => ['-25', '5', 1];
  }

  /**
   * Tests that a partial authorization does not forgive unrelated order debt.
   */
  public function testPartialCaptureAdjustmentPreservesUnrelatedDebt(): void {
    $item = $this->order->getItems()[0];
    $item->setUnitPrice(new Price('60', 'UAH'));
    $item->save();
    $this->order->set('state', 'completed');
    $this->order->recalculateTotalPrice();
    $this->order->save();
    $other = $this->createCompletedPayment('other-paid-session');
    $other->setAmount(new Price('5', 'UAH'));
    $other->save();
    $payment = $this->createAuthorizationPayment('split-capture');
    $payment->set('novapay_pending_operation', 'capture');
    $payment->set('novapay_pending_amount', '10');
    $payment->save();
    $this->container->get('commerce_novapay.postback.status_mapper')->apply($payment, NovaPayStatus::HoldConfirmed);
    $this->reloadOrder();
    self::assertEquals(new Price('40', 'UAH'), $this->order->getTotalPrice());
    self::assertEquals(new Price('15', 'UAH'), $this->order->getTotalPaid());
    self::assertEquals(new Price('25', 'UAH'), $this->order->getBalance());
    self::assertFalse($this->order->isPaid());
    self::assertCount(1, $this->order->getAdjustments());
  }

  /**
   * Tests callback rollback and retry after an order save fails.
   */
  public function testPartialCaptureAdjustmentRollsBackFailedOrderSave(): void {
    $this->order->set('state', 'completed');
    $this->order->save();
    $payment = $this->createAuthorizationPayment('adjustment-rollback');
    $payment->set('novapay_pending_operation', 'capture');
    $payment->set('novapay_pending_amount', '10');
    $payment->save();
    $processor = $this->createVerifiedPostbackProcessor();
    $body = $this->createCapturePostback('adjustment-rollback', '10');
    $database = $this->container->get('database');
    $schema = rtrim($database->getPrefix(), '.');
    self::assertMatchesRegularExpression('/^[A-Za-z0-9_]+$/D', $schema);
    $trigger = sprintf('"%s"."novapay_capture_order_failure"', $schema);
    $database->query("CREATE TRIGGER $trigger BEFORE UPDATE ON commerce_order BEGIN SELECT RAISE(FAIL, 'forced capture order failure'); END", [], ['allow_delimiter_in_query' => TRUE]);
    try {
      $processor->process($this->paymentGateway, $this->createRuntimeProvider(), $body, 'verified');
      self::fail('A failed order save must roll back capture and adjustment.');
    }
    catch (EntityStorageException $exception) {
      self::assertStringContainsString('forced capture order failure', $exception->getMessage());
    }
    finally {
      $database->query("DROP TRIGGER $trigger");
    }
    $payment = $this->reloadEntity($payment);
    self::assertInstanceOf(PaymentInterface::class, $payment);
    self::assertSame('authorization', $payment->getState()->getId());
    self::assertEquals(new Price('30', 'UAH'), $payment->getAmount());
    self::assertSame('capture', $payment->get('novapay_pending_operation')->getString());
    self::assertSame('operation-id', $payment->get('novapay_operation_id')->getString());
    $this->reloadOrder();
    self::assertSame([], $this->order->getAdjustments());
    self::assertSame([], $this->order->getData('commerce_novapay_partial_captures', []));
    self::assertEquals(new Price('30', 'UAH'), $this->order->getTotalPrice());
    self::assertEquals(new Price('0', 'UAH'), $this->order->getTotalPaid());
    self::assertSame(PostbackOutcome::Applied, $processor->process($this->paymentGateway, $this->createRuntimeProvider(), $body, 'verified')->getOutcome());
    $this->reloadOrder();
    self::assertCount(1, $this->order->getAdjustments());
    self::assertTrue($this->order->isPaid());
  }

  /**
   * Tests overlapping sessions cannot overwrite adjustments on the same order.
   */
  public function testPartialCaptureOrderLockSerializesSiblingPayments(): void {
    $item = $this->order->getItems()[0];
    $item->setUnitPrice(new Price('60', 'UAH'));
    $item->save();
    $this->order->set('state', 'completed');
    $this->order->recalculateTotalPrice();
    $this->order->save();
    $first = $this->createAuthorizationPayment('first-capture');
    $second = $this->createAuthorizationPayment('second-capture');
    foreach ([[$first, '10'], [$second, '20']] as [$payment, $amount]) {
      $payment->set('novapay_pending_operation', 'capture');
      $payment->set('novapay_pending_amount', $amount);
      $payment->save();
    }
    $lock_state = new class() {
      /**
       * Whether the simulated independent worker holds the order lock.
       */
      public bool $held = FALSE;

      /**
       * Returns the current lock state after nested callback execution.
       */
      public function isHeld(): bool {
        return $this->held;
      }

    };
    $order_lock = $this->createMock(LockBackendInterface::class);
    $order_lock->expects(self::exactly(3))->method('acquire')
      ->with('commerce_novapay_order_update:' . $this->order->id(), 120.0)
      ->willReturnCallback(static function () use ($lock_state): bool {
        if ($lock_state->held) {
          return FALSE;
        }
        return $lock_state->held = TRUE;
      });
    $order_lock->expects(self::exactly(2))->method('release')
      ->willReturnCallback(static function () use ($lock_state): void {
        self::assertTrue($lock_state->isHeld());
        $lock_state->held = FALSE;
      });
    $this->container->set('commerce_novapay.payment_order_lock', new PaymentOrderLock(
      $order_lock,
      $this->container->get('entity_type.manager'),
    ));
    $mapper = $this->container->get('commerce_novapay.postback.status_mapper');
    $interleaved_mapper = $this->createMock(PaymentStatusMapperInterface::class);
    $processor = NULL;
    $second_body = $this->createCapturePostback('second-capture', '20');
    $interleaved_mapper->expects(self::exactly(2))->method('apply')
      ->willReturnCallback(function (PaymentInterface $payment, NovaPayStatus $status) use ($mapper, $first, $second_body, &$processor, $lock_state): bool {
        self::assertTrue($lock_state->isHeld());
        if ($payment->id() === $first->id()) {
          self::assertInstanceOf(PostbackProcessor::class, $processor);
          try {
            $processor->process($this->paymentGateway, $this->createRuntimeProvider(), $second_body, 'verified');
            self::fail('A sibling session must not mutate the locked order.');
          }
          catch (PaymentGatewayException $exception) {
            self::assertStringContainsString('Another NovaPay update', $exception->getMessage());
          }
          self::assertTrue($lock_state->isHeld());
        }
        return $mapper->apply($payment, $status);
      });
    $this->container->set('commerce_novapay.postback.status_mapper', $interleaved_mapper);
    $processor = $this->createVerifiedPostbackProcessor();
    self::assertSame(PostbackOutcome::Applied, $processor->process(
      $this->paymentGateway, $this->createRuntimeProvider(), $this->createCapturePostback('first-capture', '10'), 'verified',
    )->getOutcome());
    self::assertFalse($lock_state->isHeld());
    $second = $this->reloadEntity($second);
    self::assertInstanceOf(PaymentInterface::class, $second);
    self::assertSame('authorization', $second->getState()->getId());
    self::assertSame('capture', $second->get('novapay_pending_operation')->getString());
    self::assertSame(PostbackOutcome::Applied, $processor->process(
      $this->paymentGateway, $this->createRuntimeProvider(), $second_body, 'verified',
    )->getOutcome());
    self::assertFalse($lock_state->isHeld());
    $this->reloadOrder();
    self::assertCount(2, $this->order->getAdjustments());
    self::assertEquals(new Price('30', 'UAH'), $this->order->getTotalPrice());
    self::assertEquals(new Price('30', 'UAH'), $this->order->getTotalPaid());
    self::assertTrue($this->order->isPaid());
    self::assertEquals(new Price('60', 'UAH'), $this->order->getItems()[0]->getUnitPrice());
  }

  /**
   * Creates the callback processor with a mocked verifier and optional API.
   */
  private function createVerifiedPostbackProcessor(?NovaPayApiClientInterface $client = NULL): PostbackProcessor {
    $verifier = $this->createMock(VerifierInterface::class);
    $verifier->method('verify')->willReturn(TRUE);
    $legacy = $this->createMock(SandboxLegacyVerifierInterface::class);
    $legacy->expects(self::never())->method('verify');
    if ($client === NULL) {
      $client = $this->createMock(NovaPayApiClientInterface::class);
      $client->expects(self::never())->method('getStatus');
    }
    return new PostbackProcessor(
      $verifier,
      $legacy,
      $this->container->get('commerce_novapay.postback.parser'),
      $this->container->get('entity_type.manager'),
      $this->container->get('commerce_novapay.postback.status_mapper'),
      $this->container->get('commerce_novapay.postback.event_repository'),
      $this->container->get('lock'),
      $this->container->get('commerce_novapay.refund_operation_manager'),
      $client,
      $this->container->get('commerce_novapay.payment_order_lock'),
    );
  }

  /**
   * Builds synthetic v2 evidence for a confirmed partial capture.
   */
  private function createCapturePostback(string $session, string $amount): string {
    return json_encode([
      'id' => $session,
      'status' => 'hold_confirmed',
      'payments' => [['external_id' => (string) $this->order->id(), 'amount' => $amount]],
      'operations' => [['transaction_id' => 'capture-operation-id', 'status' => 'paid']],
    ], JSON_THROW_ON_ERROR);
  }

  /**
   * Tests partial capture locking and postback-only amount/state changes.
   */
  public function testPartialCaptureAwaitsPostback(): void {
    $this->order->set('state', 'completed');
    $this->order->save();
    $payment = $this->createAuthorizationPayment('capture-session');
    $api_client = $this->createMock(NovaPayApiClientInterface::class);
    $api_client->expects(self::once())
      ->method('completeHold')
      ->with(
        self::isInstanceOf(RuntimeConfigurationProviderInterface::class),
        self::callback(static function (CompleteHoldRequest $request): bool {
          return $request->toArray() === [
            'session_id' => 'capture-session',
            'operations' => [[
              'id' => 'operation-id',
              'amount' => '10',
              'recipient_identifier' => '31316718',
            ]],
          ];
        }),
      )
      ->willReturn(new AcknowledgementResponse(200));
    $manager = $this->createAuthorizationOperationManager($api_client);

    self::assertTrue($manager->canCapture($payment));
    self::assertTrue($manager->canVoid($payment));
    $manager->capture($payment, $this->createRuntimeProvider(), new Price('10', 'UAH'));

    $payment = $this->reloadEntity($payment);
    self::assertInstanceOf(PaymentInterface::class, $payment);
    self::assertSame('authorization', $payment->getState()->getId());
    self::assertEquals(new Price('30', 'UAH'), $payment->getAmount());
    self::assertSame(
      'capture',
      $payment->get('novapay_pending_operation')->getString(),
    );
    self::assertSame(
      '10',
      $payment->get('novapay_pending_amount')->getString(),
    );
    $this->reloadOrder();
    self::assertSame([], $this->order->getAdjustments());
    self::assertEquals(new Price('30', 'UAH'), $this->order->getTotalPrice());
    self::assertFalse($this->order->isPaid());
    self::assertFalse($manager->canCapture($payment));
    self::assertFalse($manager->canVoid($payment));

    $this->expectException(InvalidRequestException::class);
    try {
      $manager->capture($payment, $this->createRuntimeProvider());
    }
    finally {
      $mapper = $this->container->get(
        'commerce_novapay.postback.status_mapper',
      );
      self::assertInstanceOf(PaymentStatusMapperInterface::class, $mapper);
      $mapper->apply($payment, NovaPayStatus::HoldConfirmed);
      $payment = $this->reloadEntity($payment);
      self::assertInstanceOf(PaymentInterface::class, $payment);
      self::assertSame('completed', $payment->getState()->getId());
      self::assertEquals(new Price('10', 'UAH'), $payment->getAmount());
      $this->reloadOrder();
      self::assertEquals(new Price('0', 'UAH'), $this->order->getBalance());
      self::assertTrue($this->order->isPaid());
      self::assertTrue(
        $payment->get('novapay_pending_operation')->isEmpty(),
      );
      self::assertTrue($payment->get('novapay_pending_amount')->isEmpty());
    }
  }

  /**
   * Tests that void is always full and remains pending until postback.
   */
  public function testAuthorizationVoidAwaitsPostback(): void {
    $payment = $this->createAuthorizationPayment('void-session');
    $api_client = $this->createMock(NovaPayApiClientInterface::class);
    $api_client->expects(self::once())
      ->method('voidPayment')
      ->with(
        self::isInstanceOf(RuntimeConfigurationProviderInterface::class),
        self::callback(static fn (VoidRequest $request): bool =>
          $request->toArray() === ['session_id' => 'void-session']),
      )
      ->willReturn(new AcknowledgementResponse(200));
    $manager = $this->createAuthorizationOperationManager($api_client);

    $manager->void($payment, $this->createRuntimeProvider());
    $payment = $this->reloadEntity($payment);
    self::assertInstanceOf(PaymentInterface::class, $payment);
    self::assertSame('authorization', $payment->getState()->getId());
    self::assertSame(
      'void',
      $payment->get('novapay_pending_operation')->getString(),
    );
    self::assertTrue($payment->get('novapay_pending_amount')->isEmpty());

    $mapper = $this->container->get(
      'commerce_novapay.postback.status_mapper',
    );
    self::assertInstanceOf(PaymentStatusMapperInterface::class, $mapper);
    $mapper->apply($payment, NovaPayStatus::Voided);
    $payment = $this->reloadEntity($payment);
    self::assertInstanceOf(PaymentInterface::class, $payment);
    self::assertSame(
      'authorization_voided',
      $payment->getState()->getId(),
    );
    self::assertTrue($payment->get('novapay_pending_operation')->isEmpty());
  }

  /**
   * Tests catalog messages for hold void without clearing uncertain outcomes.
   */
  public function testVoidFailureUsesRefundCatalogAndRetainsPending(): void {
    $payment = $this->createAuthorizationPayment('catalog-void-session');
    $api_client = $this->createMock(NovaPayApiClientInterface::class);
    $api_client->expects(self::once())->method('voidPayment')
      ->willThrowException(new ApiProcessingException(400, 'WrongAmountError'));
    try {
      $this->createAuthorizationOperationManager($api_client)
        ->void($payment, $this->createRuntimeProvider());
      self::fail('The rejected hold void must fail.');
    }
    catch (PaymentGatewayException $exception) {
      self::assertStringContainsString('refund amount is invalid', $exception->getMessage());
      self::assertStringContainsString('Wait for postback', $exception->getMessage());
    }
    $payment = $this->reloadEntity($payment);
    self::assertInstanceOf(PaymentInterface::class, $payment);
    self::assertSame('authorization', $payment->getState()->getId());
    self::assertFalse($this->createAuthorizationOperationManager($api_client)->canVoid($payment));
  }

  /**
   * Tests that capture does not use refund-specific catalog messages.
   */
  public function testCaptureFailureDoesNotUseRefundCatalog(): void {
    $payment = $this->createAuthorizationPayment('catalog-capture-session');
    $api_client = $this->createMock(NovaPayApiClientInterface::class);
    $api_client->expects(self::once())->method('completeHold')
      ->willThrowException(new ApiProcessingException(400, 'WrongAmountError'));
    try {
      $this->createAuthorizationOperationManager($api_client)
        ->capture($payment, $this->createRuntimeProvider());
      self::fail('The failed capture must fail.');
    }
    catch (PaymentGatewayException $exception) {
      self::assertStringNotContainsString('refund', $exception->getMessage());
      self::assertStringContainsString('Wait for postback', $exception->getMessage());
    }
    $payment = $this->reloadEntity($payment);
    self::assertInstanceOf(PaymentInterface::class, $payment);
    self::assertSame('authorization', $payment->getState()->getId());
    self::assertFalse($this->createAuthorizationOperationManager($api_client)->canCapture($payment));
  }

  /**
   * Tests that a full capture omits partial-capture API properties.
   */
  public function testFullCapturePayload(): void {
    $payment = $this->createAuthorizationPayment('full-capture-session');
    $api_client = $this->createMock(NovaPayApiClientInterface::class);
    $api_client->expects(self::once())
      ->method('completeHold')
      ->with(
        self::isInstanceOf(RuntimeConfigurationProviderInterface::class),
        self::callback(static fn (CompleteHoldRequest $request): bool =>
          $request->toArray() === [
            'session_id' => 'full-capture-session',
          ]),
      )
      ->willReturn(new AcknowledgementResponse(200));

    $lock = $this->createMock(LockBackendInterface::class);
    $lock_name = SessionLockName::fromSessionId('full-capture-session');
    $lock->expects(self::once())->method('acquire')
      ->with($lock_name, 30.0)
      ->willReturn(TRUE);
    $lock->expects(self::once())->method('release')->with($lock_name);
    $manager = $this->createAuthorizationOperationManager($api_client, $lock);
    $manager->capture($payment, $this->createRuntimeProvider());

    $payment = $this->reloadEntity($payment);
    self::assertInstanceOf(PaymentInterface::class, $payment);
    self::assertSame('authorization', $payment->getState()->getId());
    self::assertSame(
      '30',
      $payment->get('novapay_pending_amount')->getString(),
    );
  }

  /**
   * Tests that an uncertain transport result remains blocked for postback.
   */
  public function testTransportFailureRetainsPendingMarker(): void {
    $payment = $this->createAuthorizationPayment('uncertain-void-session');
    $api_client = $this->createMock(NovaPayApiClientInterface::class);
    $api_client->expects(self::once())
      ->method('voidPayment')
      ->willThrowException(ApiTransportException::requestFailed());
    $manager = $this->createAuthorizationOperationManager($api_client);

    try {
      $manager->void($payment, $this->createRuntimeProvider());
      self::fail('An uncertain transport result must fail the form request.');
    }
    catch (PaymentGatewayException $exception) {
      self::assertStringContainsString('uncertain', $exception->getMessage());
    }

    $payment = $this->reloadEntity($payment);
    self::assertInstanceOf(PaymentInterface::class, $payment);
    self::assertSame('authorization', $payment->getState()->getId());
    self::assertSame(
      'void',
      $payment->get('novapay_pending_operation')->getString(),
    );
    self::assertFalse($manager->canVoid($payment));
  }

  /**
   * Tests that fatal responses retain the in-flight financial marker.
   */
  public function testFatalFailureRetainsPendingMarker(): void {
    $this->assertUncertainVoidFailureRetainsMarker(
      new ApiFatalException(500),
      'fatal-void-session',
    );
  }

  /**
   * Tests that processing outcomes retain the in-flight financial marker.
   */
  public function testProcessingFailureRetainsPendingMarker(): void {
    $this->assertUncertainVoidFailureRetainsMarker(
      new ApiProcessingException(400, 'TimeoutError'),
      'processing-void-session',
    );
  }

  /**
   * Tests that malformed server failures retain the financial marker.
   */
  public function testMalformedServerFailureRetainsPendingMarker(): void {
    $this->assertUncertainVoidFailureRetainsMarker(
      ApiUnexpectedResponseException::invalidError(502),
      'malformed-server-void-session',
    );
  }

  /**
   * Tests that a definitive validation rejection permits a corrected retry.
   */
  public function testValidationFailureClearsPendingMarker(): void {
    $payment = $this->createAuthorizationPayment('validation-void-session');
    $api_client = $this->createMock(NovaPayApiClientInterface::class);
    $api_client->expects(self::once())
      ->method('voidPayment')
      ->willThrowException(new ApiValidationException(400, []));
    $manager = $this->createAuthorizationOperationManager($api_client);

    try {
      $manager->void($payment, $this->createRuntimeProvider());
      self::fail('A validation rejection must fail the form request.');
    }
    catch (PaymentGatewayException $exception) {
      self::assertStringContainsString('rejected', $exception->getMessage());
    }

    $payment = $this->reloadEntity($payment);
    self::assertInstanceOf(PaymentInterface::class, $payment);
    self::assertTrue($payment->get('novapay_pending_operation')->isEmpty());
    self::assertTrue($manager->canVoid($payment));
  }

  /**
   * Tests that an expired authorization is unavailable and never submitted.
   */
  public function testExpiredAuthorizationIsUnavailable(): void {
    $payment = $this->createAuthorizationPayment('expired-hold-session');
    $payment->setExpiresTime(1);
    $payment->save();
    $api_client = $this->createMock(NovaPayApiClientInterface::class);
    $api_client->expects(self::never())->method('completeHold');
    $manager = $this->createAuthorizationOperationManager($api_client);

    self::assertFalse($manager->canCapture($payment));
    self::assertFalse($manager->canVoid($payment));
    $this->expectException(InvalidRequestException::class);
    $manager->capture($payment, $this->createRuntimeProvider());
  }

  /**
   * Tests capture amount bounds before a financial API request is sent.
   */
  public function testCaptureAmountBounds(): void {
    $api_client = $this->createMock(NovaPayApiClientInterface::class);
    $api_client->expects(self::never())->method('completeHold');
    $manager = $this->createAuthorizationOperationManager($api_client);
    $payment = $this->createAuthorizationPayment('invalid-amount-session');

    foreach ([
      ['-1', 'UAH'],
      ['0', 'UAH'],
      ['0.001', 'UAH'],
      ['1.001', 'UAH'],
      ['30.01', 'UAH'],
      ['30.0000001', 'UAH'],
      ['1e1', 'UAH'],
      ['1', 'USD'],
    ] as [$amount, $currency]) {
      try {
        $manager->capture(
          $payment,
          $this->createRuntimeProvider(),
          new Price($amount, $currency),
        );
        self::fail('An invalid capture amount must be rejected.');
      }
      catch (InvalidRequestException $exception) {
        self::assertSame(
          'The capture amount must be greater than zero and must not exceed the authorized amount.',
          $exception->getMessage(),
        );
      }
    }
    $payment = $this->reloadEntity($payment);
    self::assertInstanceOf(PaymentInterface::class, $payment);
    self::assertSame('authorization', $payment->getState()->getId());
    self::assertTrue($payment->get('novapay_pending_operation')->isEmpty());
    self::assertTrue($payment->get('novapay_pending_amount')->isEmpty());
  }

  /**
   * Tests an explicitly designated customer telephone field as a source.
   */
  public function testDesignatedCustomerPhoneField(): void {
    FieldStorageConfig::create([
      'field_name' => 'field_payment_phone',
      'entity_type' => 'user',
      'type' => 'telephone',
    ])->save();
    $field = FieldConfig::create([
      'field_name' => 'field_payment_phone',
      'entity_type' => 'user',
      'bundle' => 'user',
      'label' => 'Payment phone',
    ]);
    $field->setThirdPartySetting(
      'commerce_novapay',
      'payment_phone',
      TRUE,
    );
    $field->save();

    $customer = User::create([
      'name' => 'novapay-phone-customer',
      'status' => TRUE,
      'field_payment_phone' => '050 123 45 67',
    ]);
    $customer->save();
    $this->order->setCustomer($customer);

    $resolver = $this->container->get(
      'commerce_novapay.order_phone_resolver',
    );
    self::assertInstanceOf(OrderPhoneResolverInterface::class, $resolver);
    self::assertSame('+380501234567', $resolver->resolve($this->order));
  }

  /**
   * Tests the Field UI payment-phone checkbox and persisted setting.
   */
  public function testTelephoneFieldConfigurationForm(): void {
    FieldStorageConfig::create([
      'field_name' => 'field_checkout_phone',
      'entity_type' => 'user',
      'type' => 'telephone',
    ])->save();
    $field = FieldConfig::create([
      'field_name' => 'field_checkout_phone',
      'entity_type' => 'user',
      'bundle' => 'user',
      'label' => 'Checkout phone',
    ]);
    $field->save();
    $form_object = $this->createMock(EntityFormInterface::class);
    $form_object->method('getEntity')->willReturn($field);
    $form_state = (new FormState())->setFormObject($form_object);
    $form = [
      'actions' => [
        'submit' => ['#submit' => []],
      ],
    ];

    $form_id = 'field_config_edit_form';
    $this->container->get('module_handler')->alter('form_' . $form_id, $form, $form_state, $form_id);

    self::assertArrayHasKey('commerce_novapay_payment_phone', $form);
    self::assertFalse(
      $form['commerce_novapay_payment_phone']['#default_value'],
    );
    self::assertContains(
      [FieldConfigFormHooks::class, 'submit'],
      $form['actions']['submit']['#submit'],
    );

    $form_state->setValue('commerce_novapay_payment_phone', TRUE);
    call_user_func($form['actions']['submit']['#submit'][0], $form, $form_state);
    $field = FieldConfig::load($field->id());
    self::assertInstanceOf(FieldConfig::class, $field);
    self::assertTrue((bool) $field->getThirdPartySetting(
      'commerce_novapay',
      'payment_phone',
      FALSE,
    ));
    $form_state->setValue('commerce_novapay_payment_phone', FALSE);
    call_user_func($form['actions']['submit']['#submit'][0], $form, $form_state);
    $field = FieldConfig::load($field->id());
    self::assertInstanceOf(FieldConfig::class, $field);
    self::assertFalse((bool) $field->getThirdPartySetting('commerce_novapay', 'payment_phone', FALSE));
  }

  /**
   * Tests attributed payment form discovery and operation-specific labels.
   */
  public function testPaymentFormHooks(): void {
    $payment = $this->container->get('entity_type.manager')
      ->getStorage('commerce_payment')->create([
        'payment_gateway' => $this->paymentGateway,
      ]);
    $form_object = $this->createMock(EntityFormInterface::class);
    $form_object->method('getEntity')->willReturn($payment);
    $form_state = (new FormState())->setFormObject($form_object);
    $request = Request::create('/refund');
    $request->attributes->add([
      '_route' => 'commerce_novapay_test',
      '_route_object' => new Route('/{operation}'),
      '_raw_variables' => new ParameterBag(['operation' => 'refund']),
      'operation' => 'refund',
    ]);
    $stack = $this->container->get('request_stack');
    $stack->push($request);
    try {
      $form_id = 'commerce_payment_form';
      $form = ['actions' => [
        'submit' => ['#value' => 'Original'],
        'cancel' => ['#title' => 'Cancel'],
      ]];
      $this->container->get('module_handler')->alter('form_' . $form_id, $form, $form_state, $form_id);
      self::assertSame('Refund via NovaPay', (string) $form['actions']['submit']['#value']);
      self::assertSame('NovaPay refund submission', $form['actions']['submit']['#value']->getOption('context'));
      self::assertSame('Cancel', $form['actions']['cancel']['#title']);
    }
    finally {
      $stack->pop();
    }
  }

  /**
   * Tests readiness states for Commerce customer profile telephone fields.
   */
  public function testCustomerProfilePhoneReadiness(): void {
    $profile_type = ProfileType::load('customer');
    self::assertInstanceOf(ProfileType::class, $profile_type);
    self::assertTrue((bool) $profile_type->getThirdPartySetting(
      'commerce_order',
      'customer_profile_type',
      FALSE,
    ));
    $inspector = $this->container->get(
      'commerce_novapay.customer_profile_phone_inspector',
    );
    self::assertInstanceOf(
      CustomerProfilePhoneInspectorInterface::class,
      $inspector,
    );

    $readiness = $inspector->inspect();
    self::assertFalse($readiness->isReady());
    self::assertSame(['Customer'], $readiness->getMissingTelephone());
    self::assertSame([], $readiness->getUnmarkedTelephone());

    FieldStorageConfig::create([
      'field_name' => 'field_profile_phone',
      'entity_type' => 'profile',
      'type' => 'telephone',
    ])->save();
    $field = FieldConfig::create([
      'field_name' => 'field_profile_phone',
      'entity_type' => 'profile',
      'bundle' => 'customer',
      'label' => 'Customer phone',
    ]);
    $field->save();

    $readiness = $inspector->inspect();
    self::assertFalse($readiness->isReady());
    self::assertSame([], $readiness->getMissingTelephone());
    self::assertSame(['Customer'], $readiness->getUnmarkedTelephone());

    $field->setThirdPartySetting(
      'commerce_novapay',
      'payment_phone',
      TRUE,
    );
    $field->save();
    $readiness = $inspector->inspect();
    self::assertTrue($readiness->isReady());
    self::assertSame([], $readiness->getMissingTelephone());
    self::assertSame([], $readiness->getUnmarkedTelephone());
  }

  /**
   * Reloads the typed parent order.
   */
  private function reloadOrder(): void {
    $order = $this->reloadEntity($this->order);
    assert($order instanceof Order);
    $this->order = $order;
  }

  /**
   * Tests direct and hold payment reconciliation without a postback.
   */
  public function testReconcilesPaymentStatusWithoutFinancialRequest(): void {
    $direct = $this->createPendingPayment('direct-status-session');
    $direct_client = $this->createMock(NovaPayApiClientInterface::class);
    $direct_client->expects(self::once())->method('getStatus')
      ->willReturn(SessionStatusResponse::fromArray([
        'id' => 'direct-status-session',
        'status' => 'paid',
      ]));
    $direct_manager = $this->createPaymentStatusCheckManager($direct_client);

    self::assertSame(
      PaymentStatusCheckResult::Reconciled,
      $direct_manager->checkStatus($direct, $this->createRuntimeProvider()),
    );
    $direct = $this->reloadEntity($direct);
    self::assertInstanceOf(PaymentInterface::class, $direct);
    self::assertSame('completed', $direct->getState()->getId());
    self::assertSame('paid', $direct->getRemoteState());

    $hold = $this->createAuthorizationPayment('hold-status-session');
    $hold->set('novapay_pending_operation', 'capture');
    $hold->set('novapay_pending_amount', '30');
    $hold->save();
    $hold_client = $this->createMock(NovaPayApiClientInterface::class);
    $hold_client->expects(self::once())->method('getStatus')
      ->willReturn(SessionStatusResponse::fromArray([
        'id' => 'hold-status-session',
        'status' => 'hold_confirmed',
      ]));
    $hold_manager = $this->createPaymentStatusCheckManager($hold_client);

    self::assertSame(
      PaymentStatusCheckResult::Reconciled,
      $hold_manager->checkStatus($hold, $this->createRuntimeProvider()),
    );
    $hold = $this->reloadEntity($hold);
    self::assertInstanceOf(PaymentInterface::class, $hold);
    self::assertSame('completed', $hold->getState()->getId());
    self::assertTrue($hold->get('novapay_pending_operation')->isEmpty());
  }

  /**
   * Tests that shared order locking reloads an intervening order edit.
   */
  public function testPaymentStatusCheckReloadsConcurrentOrderEdit(): void {
    $this->order->set('state', 'completed');
    $this->order->save();
    $payment = $this->createAuthorizationPayment('conflict-status-session');
    $payment->set('novapay_pending_operation', 'capture');
    $payment->set('novapay_pending_amount', '10');
    $payment->save();
    $payment_storage = $this->container->get('entity_type.manager')->getStorage('commerce_payment');
    $order_storage = $this->container->get('entity_type.manager')->getStorage('commerce_order');
    $api_client = $this->createMock(NovaPayApiClientInterface::class);
    $api_client->expects(self::once())->method('getStatus')
      ->willReturnCallback(function () use ($payment, $payment_storage, $order_storage): SessionStatusResponse {
        $current = $payment_storage->load($payment->id());
        self::assertInstanceOf(PaymentInterface::class, $current);
        $stale_order = $current->getOrder();
        $order_storage->resetCache();
        $fresh_order = $order_storage->load($stale_order->id());
        self::assertInstanceOf(OrderInterface::class, $fresh_order);
        $fresh_order->setData('concurrent_edit', 'preserved');
        $fresh_order->save();
        return SessionStatusResponse::fromArray([
          'id' => 'conflict-status-session',
          'status' => 'paid',
          'operations' => [['transaction_id' => 'conflict-capture-id', 'status' => 'paid']],
        ]);
      });
    foreach (['createSession', 'addPayment', 'completeHold', 'voidPayment'] as $method) {
      $api_client->expects(self::never())->method($method);
    }
    $manager = $this->createPaymentStatusCheckManager($api_client);
    self::assertSame(PaymentStatusCheckResult::Reconciled, $manager->checkStatus($payment, $this->createRuntimeProvider()));
    $payment = $this->reloadEntity($payment);
    self::assertInstanceOf(PaymentInterface::class, $payment);
    self::assertSame('completed', $payment->getState()->getId());
    self::assertTrue($payment->get('novapay_pending_operation')->isEmpty());
    $this->reloadOrder();
    self::assertSame('preserved', $this->order->getData('concurrent_edit'));
    self::assertEquals(new Price('10', 'UAH'), $this->order->getTotalPaid());
    self::assertTrue($this->order->isPaid());
    self::assertCount(1, $this->order->getAdjustments());
  }

  /**
   * Tests that inconclusive and mismatching results cannot mutate a payment.
   */
  public function testPaymentStatusCheckPreservesPendingState(): void {
    $payment = $this->createAuthorizationPayment('pending-status-session');
    $payment->set('novapay_pending_operation', 'void');
    $payment->save();
    $api_client = $this->createMock(NovaPayApiClientInterface::class);
    $api_client->expects(self::once())->method('getStatus')
      ->willReturn(SessionStatusResponse::fromArray([
        'id' => 'pending-status-session',
        'status' => 'processing_void',
      ]));
    $manager = $this->createPaymentStatusCheckManager($api_client);

    self::assertSame(
      PaymentStatusCheckResult::Reconciled,
      $manager->checkStatus($payment, $this->createRuntimeProvider()),
    );
    $payment = $this->reloadEntity($payment);
    self::assertInstanceOf(PaymentInterface::class, $payment);
    self::assertSame('authorization', $payment->getState()->getId());
    self::assertSame('void', $payment->get('novapay_pending_operation')->getString());
    self::assertSame('processing_void', $payment->getRemoteState());
  }

  /**
   * Tests that a confirmed unchanged hold unblocks a failed capture retry.
   */
  public function testPaymentStatusCheckClearsPendingCaptureForHolded(): void {
    $payment = $this->createAuthorizationPayment('holded-status-session');
    $payment->set('novapay_pending_operation', 'capture');
    $payment->set('novapay_pending_amount', '10');
    $payment->save();
    $api_client = $this->createMock(NovaPayApiClientInterface::class);
    $api_client->expects(self::once())->method('getStatus')
      ->willReturn(SessionStatusResponse::fromArray([
        'id' => 'holded-status-session',
        'status' => 'holded',
        'operations' => [[
          'transaction_id' => 'operation-id',
          'refunded_amount' => NULL,
        ]],
      ]));
    $manager = $this->createPaymentStatusCheckManager($api_client);

    self::assertSame(
      PaymentStatusCheckResult::Reconciled,
      $manager->checkStatus($payment, $this->createRuntimeProvider()),
    );
    $payment = $this->reloadEntity($payment);
    self::assertInstanceOf(PaymentInterface::class, $payment);
    self::assertSame('authorization', $payment->getState()->getId());
    self::assertSame('holded', $payment->getRemoteState());
    self::assertTrue($payment->get('novapay_pending_operation')->isEmpty());
    self::assertTrue($payment->get('novapay_pending_amount')->isEmpty());
  }

  /**
   * Creates a persisted NovaPay authorization for operation tests.
   */
  private function createAuthorizationPayment(
    string $session_id,
  ): PaymentInterface {
    $payment_storage = $this->container
      ->get('entity_type.manager')
      ->getStorage('commerce_payment');
    $payment = $payment_storage->create([
      'payment_gateway' => $this->paymentGateway,
      'order_id' => $this->order->id(),
      'amount' => new Price('30', 'UAH'),
      'state' => 'authorization',
      'remote_id' => $session_id,
      'remote_state' => 'holded',
      'novapay_operation_id' => 'operation-id',
    ]);
    self::assertInstanceOf(PaymentInterface::class, $payment);
    $payment->save();
    return $payment;
  }

  /**
   * Creates a persisted pending NovaPay payment for direct status checks.
   */
  private function createPendingPayment(string $session_id): PaymentInterface {
    $payment_storage = $this->container
      ->get('entity_type.manager')
      ->getStorage('commerce_payment');
    $payment = $payment_storage->create([
      'payment_gateway' => $this->paymentGateway,
      'order_id' => $this->order->id(),
      'amount' => new Price('30', 'UAH'),
      'state' => 'pending',
      'remote_id' => $session_id,
      'remote_state' => 'created',
    ]);
    self::assertInstanceOf(PaymentInterface::class, $payment);
    $payment->save();
    return $payment;
  }

  /**
   * Tests missing/stale session and concurrent refunds fail before HTTP.
   */
  public function testRefundSessionGuardsPreventFinancialRequest(): void {
    $api = $this->createMock(NovaPayApiClientInterface::class);
    $api->expects(self::never())->method('voidPayment');
    $manager = $this->createRefundOperationManager($api);
    $storage = $this->container->get('entity_type.manager')->getStorage('commerce_payment');
    $unsaved = $storage->create(['payment_gateway' => $this->paymentGateway]);
    self::assertInstanceOf(PaymentInterface::class, $unsaved);
    $payment = $this->createCompletedPayment('guard-session');
    foreach ([$unsaved, $payment->setRemoteId(''), $this->reloadEntity($payment)] as $candidate) {
      self::assertInstanceOf(PaymentInterface::class, $candidate);
      if ($candidate->getRemoteId() === 'guard-session') {
        $candidate->setRemoteId('stale-session');
      }
      try {
        $manager->refund($candidate, $this->createRuntimeProvider());
        self::fail('Unsafe session identity must reject the financial request.');
      }
      catch (InvalidRequestException) {
        self::assertTrue($candidate->get('novapay_pending_operation')->isEmpty());
      }
    }
    $payment = $this->reloadEntity($payment);
    self::assertInstanceOf(PaymentInterface::class, $payment);
    $lock = $this->createMock(LockBackendInterface::class);
    $lock->method('acquire')->willReturn(FALSE);
    $lock->expects(self::never())->method('release');
    $manager = $this->createRefundOperationManager($api, $lock);
    try {
      $manager->refund($payment, $this->createRuntimeProvider());
      self::fail('A busy session must not submit a refund.');
    }
    catch (PaymentGatewayException) {
      self::assertTrue($payment->get('novapay_pending_operation')->isEmpty());
      self::assertSame('completed', $payment->getState()->getId());
      self::assertEquals(new Price('0', 'UAH'), $payment->getRefundedAmount());
      self::assertSame(0, $this->countRefundLedgerRows($payment));
    }
  }

  /**
   * Tests invalid selections cannot send HTTP or leave a pending intent.
   */
  public function testInvalidRefundSelectionLeavesFinancialStateUnchanged(): void {
    $this->setQuantityStep('0.5');
    $payment = $this->createCompletedPayment('invalid-selection-session');
    $item_id = (int) $this->order->getItems()[0]->id();
    $api_client = $this->createMock(NovaPayApiClientInterface::class);
    $api_client->expects(self::never())->method('voidPayment');
    $manager = $this->createRefundOperationManager($api_client);
    foreach ([
      [$item_id => '-1'], [$item_id => '1e2'], [$item_id => '2'],
      [$item_id + 1000 => '1'], [$item_id + 1000 => '0'],
    ] as $quantities) {
      try {
        $manager->refund($payment, $this->createRuntimeProvider(), $quantities);
        self::fail('Invalid selection must be rejected before HTTP.');
      }
      catch (InvalidRequestException) {
        $payment = $this->reloadEntity($payment);
        self::assertInstanceOf(PaymentInterface::class, $payment);
        self::assertSame('completed', $payment->getState()->getId());
        self::assertTrue($payment->get('novapay_pending_operation')->isEmpty());
        self::assertEquals(new Price('0', 'UAH'), $payment->getRefundedAmount());
        self::assertSame(0, $this->countRefundLedgerRows($payment));
      }
    }
    $payment->set('novapay_operation_id', NULL)->save();
    try {
      $manager->refund($payment, $this->createRuntimeProvider(), [$item_id => '0.5']);
      self::fail('Partial refunds require an operation identifier.');
    }
    catch (InvalidRequestException $exception) {
      self::assertStringContainsString('operation ID', $exception->getMessage());
      self::assertFalse($manager->canCheckStatus($payment));
    }
  }

  /**
   * Tests HTTP failure, mismatched session and terminal partial-refund status.
   */
  public function testStatusFailuresPreservePendingPartialRefund(): void {
    $this->setQuantityStep('0.5');
    $payment = $this->createCompletedPayment('status-failure-session');
    $item_id = (int) $this->order->getItems()[0]->id();
    $api_client = $this->createMock(NovaPayApiClientInterface::class);
    $api_client->expects(self::once())->method('voidPayment')->willReturn(new AcknowledgementResponse(200));
    $attempt = 0;
    $api_client->expects(self::exactly(3))->method('getStatus')->willReturnCallback(
      static function () use (&$attempt): SessionStatusResponse {
        $attempt++;
        if ($attempt === 1) {
          throw ApiTransportException::requestFailed();
        }
        return SessionStatusResponse::fromArray([
          'id' => $attempt === 2 ? 'foreign-session' : 'status-failure-session',
          'status' => 'voided',
        ]);
      },
    );
    $manager = $this->createRefundOperationManager($api_client);
    $manager->refund($payment, $this->createRuntimeProvider(), [$item_id => '0.5']);
    $payment = $this->reloadEntity($payment);
    self::assertInstanceOf(PaymentInterface::class, $payment);
    for ($i = 0; $i < 2; $i++) {
      try {
        $manager->checkStatus($payment, $this->createRuntimeProvider());
        self::fail('An uncertain or foreign status must be rejected.');
      }
      catch (PaymentGatewayException) {
        self::assertTrue($manager->canCheckStatus($payment));
      }
    }
    self::assertSame(RefundStatusCheckResult::Pending, $manager->checkStatus($payment, $this->createRuntimeProvider()));
    $payment = $this->reloadEntity($payment);
    self::assertInstanceOf(PaymentInterface::class, $payment);
    self::assertTrue($manager->canCheckStatus($payment));
    self::assertSame('completed', $payment->getState()->getId());
    self::assertEquals(new Price('0', 'UAH'), $payment->getRefundedAmount());
    self::assertSame(0, $this->countRefundLedgerRows($payment));
  }

  /**
   * Tests corrupted local refund intents cannot update money or the ledger.
   */
  public function testCorruptPendingRefundIsNeverConfirmed(): void {
    $payment = $this->createCompletedPayment('corrupt-intent-session');
    $api_client = $this->createMock(NovaPayApiClientInterface::class);
    $api_client->expects(self::never())->method('voidPayment');
    $api_client->expects(self::never())->method('getStatus');
    $manager = $this->createRefundOperationManager($api_client);
    $payment->set('novapay_pending_operation', 'refund');
    $valid = ['full' => FALSE, 'processing_seen' => FALSE, 'items' => []];
    $payloads = [
      '{',
      'null',
      json_encode($valid, JSON_THROW_ON_ERROR),
      json_encode(array_replace($valid, ['items' => ['invalid']]), JSON_THROW_ON_ERROR),
      json_encode(array_replace($valid, ['items' => [[
        'order_item_id' => (int) $this->order->getItems()[0]->id(),
        'quantity' => '-1',
        'amount' => '15',
      ]]]), JSON_THROW_ON_ERROR),
    ];
    foreach ($payloads as $payload) {
      $payment->set('novapay_pending_refund', $payload)->save();
      try {
        $manager->confirm($payment, NormalizedPostbackEvent::fromValues('corrupt-intent-session', 'paid', [], ['15']), hash('sha256', $payload));
        self::fail('Corrupt local intent must not be confirmed.');
      }
      catch (\UnexpectedValueException | InvalidRequestException) {
        self::assertFalse($manager->hasPendingPartialRefund($payment));
        self::assertEquals(new Price('0', 'UAH'), $payment->getRefundedAmount());
        self::assertSame(0, $this->countRefundLedgerRows($payment));
      }
    }
  }

  /**
   * Tests compensation, stale confirmation, mixed intents and full remainder.
   */
  public function testMonetaryRefundAndSharedItemBalance(): void {
    $payment = $this->createCompletedPayment('money-refund');
    /** @var list<array<string, mixed>> $requests */
    $requests = [];
    $client = $this->createMock(NovaPayApiClientInterface::class);
    $client->method('voidPayment')->willReturnCallback(
      static function (RuntimeConfigurationProviderInterface $gateway, VoidRequest $request) use (&$requests): AcknowledgementResponse {
        $requests[] = $request->toArray();
        return new AcknowledgementResponse(200);
      },
    );
    $manager = $this->createRefundOperationManager($client);
    $manager->refundAmount($payment, $this->createRuntimeProvider(), '10.00');
    $payment = $this->reloadEntity($payment);
    self::assertInstanceOf(PaymentInterface::class, $payment);
    self::assertSame('10', $requests[0]['operations'][0]['refund_amount']);
    self::assertSame(0, $this->countRefundLedgerRows($payment));
    $pending = json_decode($payment->get('novapay_pending_refund')->getString(), TRUE);
    self::assertSame('amount', $pending['mode']);
    self::assertNull($pending['items'][0]['order_item_id']);
    self::assertSame('0', $pending['items'][0]['quantity']);
    self::assertFalse($manager->canRefund($payment));
    $manager->confirm($payment, NormalizedPostbackEvent::fromValues('money-refund', 'paid', [], ['0']), hash('sha256', 'stale-money'));
    self::assertSame(0, $this->countRefundLedgerRows($payment));
    $manager->confirm($payment, NormalizedPostbackEvent::fromValues('money-refund', 'paid', [], ['10']), hash('sha256', 'money-confirmed'));
    self::assertSame(1, $this->countRefundLedgerRows($payment));
    $manager->confirm($payment, NormalizedPostbackEvent::fromValues('money-refund', 'paid', [], ['10']), hash('sha256', 'money-confirmed'));
    self::assertSame(1, $this->countRefundLedgerRows($payment));
    self::assertSame([], $this->container->get('commerce_novapay.refund_ledger')->getRefundedQuantities((int) $payment->id()));
    self::assertEquals(new Price('20', 'UAH'), $payment->getBalance());
    $manager->refund($payment, $this->createRuntimeProvider(), [(int) $this->order->getItems()[0]->id() => '1']);
    self::assertSame(['session_id' => 'money-refund'], $requests[1]);
    $payment = $this->reloadEntity($payment);
    self::assertInstanceOf(PaymentInterface::class, $payment);
    self::assertFalse($manager->hasPendingPartialRefund($payment));
    $manager->confirm($payment, NormalizedPostbackEvent::fromValues('money-refund', 'voided', [], []), hash('sha256', 'full-money'));
    self::assertEquals(new Price('30', 'UAH'), $payment->getRefundedAmount());
    self::assertSame(2, $this->countRefundLedgerRows($payment));
  }

  /**
   * Tests the captured balance and availability without refundable item rows.
   */
  public function testMonetaryRefundUsesCapturedBalanceWithoutItems(): void {
    $payment = $this->createAuthorizationPayment('captured-money');
    $payment->setAmount(new Price('1000', 'UAH'));
    $payment->save();
    $capture_client = $this->createMock(NovaPayApiClientInterface::class);
    $capture_client->expects(self::once())->method('completeHold')->willReturn(new AcknowledgementResponse(200));
    $this->createAuthorizationOperationManager($capture_client)->capture($payment, $this->createRuntimeProvider(), new Price('600', 'UAH'));
    $payment = $this->reloadEntity($payment);
    self::assertInstanceOf(PaymentInterface::class, $payment);
    self::assertFalse($this->container->get('commerce_novapay.refund_operation_manager')->canRefund($payment));
    $this->createVerifiedPostbackProcessor()->process(
      $this->paymentGateway, $this->createRuntimeProvider(),
      $this->createCapturePostback('captured-money', '600'), 'verified',
    );
    $payment = $this->reloadEntity($payment);
    self::assertInstanceOf(PaymentInterface::class, $payment);
    self::assertEquals(new Price('600', 'UAH'), $payment->getAmount());
    $item = $this->order->getItems()[0];
    $item->setUnitPrice(new Price('0', 'UAH'));
    $item->save();
    /** @var list<array<string, mixed>> $requests */
    $requests = [];
    $client = $this->createMock(NovaPayApiClientInterface::class);
    $client->method('voidPayment')->willReturnCallback(
      static function (RuntimeConfigurationProviderInterface $gateway, VoidRequest $request) use (&$requests): AcknowledgementResponse {
        $requests[] = $request->toArray();
        return new AcknowledgementResponse(200);
      },
    );
    $manager = $this->createRefundOperationManager($client);
    self::assertTrue($manager->canRefund($payment));
    try {
      $manager->refundAmount($payment, $this->createRuntimeProvider(), '600.01');
      self::fail('Cannot refund authorization or order value beyond capture.');
    }
    catch (InvalidRequestException) {
      self::assertCount(0, $requests);
    }
    self::assertSame([], $manager->getRefundableItems($payment));
    $manager->refund($payment, $this->createRuntimeProvider());
    /** @var list<array<string, mixed>> $requests */
    self::assertSame(['session_id' => 'captured-money'], $requests[0]);
    $payment = $this->reloadEntity($payment);
    self::assertInstanceOf(PaymentInterface::class, $payment);
    $manager->confirm($payment, NormalizedPostbackEvent::fromValues('captured-money', 'voided', []), hash('sha256', 'captured-full'));
    self::assertEquals(new Price('600', 'UAH'), $payment->getRefundedAmount());
    self::assertEquals(new Price('0', 'UAH'), $payment->getBalance());
    $this->reloadOrder();
    self::assertEquals(new Price('0', 'UAH'), $this->order->getTotalPaid());
  }

  /**
   * Tests schema upgrade, old pending intent, and repeat migration safety.
   */
  public function testRefundUpgradePreservesLegacyPendingAndLedger(): void {
    $payment = $this->createCompletedPayment('legacy-upgrade');
    $item_id = (int) $this->order->getItems()[0]->id();
    $database = $this->container->get('database');
    $schema = $database->schema();
    $table = 'commerce_novapay_refund_ledger';
    $schema->dropUniqueKey($table, 'event_selection');
    $schema->dropField($table, 'selection_key');
    $schema->addUniqueKey($table, 'event_item', ['event_key', 'order_item_id']);
    $database->insert($table)->fields([
      'payment_id' => $payment->id(), 'order_item_id' => $item_id,
      'quantity' => '0.1', 'amount' => '3', 'currency_code' => 'UAH',
      'event_key' => hash('sha256', 'old-ledger'), 'confirmed' => 1,
    ])->execute();
    $payment->setRefundedAmount(new Price('3', 'UAH'));
    $payment->setState('partially_refunded');
    $payment->set('novapay_pending_operation', 'refund');
    $payment->set('novapay_pending_amount', '3');
    $pending = json_encode(['full' => FALSE, 'processing_seen' => FALSE, 'items' => [[
      'order_item_id' => $item_id, 'quantity' => '0.1', 'amount' => '3.000000',
    ]]], JSON_THROW_ON_ERROR);
    $payment->set('novapay_pending_refund', $pending);
    $payment->save();
    require_once DRUPAL_ROOT . '/modules/custom/commerce_novapay/commerce_novapay.install';
    commerce_novapay_update_11007();
    commerce_novapay_update_11007();
    self::assertSame($pending, $payment->get('novapay_pending_refund')->getString());
    self::assertSame('item:' . $item_id, $database->select($table, 'r')->fields('r', ['selection_key'])->execute()->fetchField());
    $client = $this->createMock(NovaPayApiClientInterface::class);
    $client->expects(self::never())->method('voidPayment');
    $manager = $this->createRefundOperationManager($client);
    $manager->confirm($payment, NormalizedPostbackEvent::fromValues('legacy-upgrade', 'paid', [], ['6']), hash('sha256', 'legacy-confirm'));
    self::assertEquals(new Price('6', 'UAH'), $payment->getRefundedAmount());
    self::assertSame(2, $this->countRefundLedgerRows($payment));
  }

  /**
   * Tests processing followed by an older paid event cannot confirm intent.
   */
  public function testMonetaryRefundRequiresCumulativeEvidenceAfterProcessing(): void {
    $payment = $this->createCompletedPayment('stale-paid-money');
    $payment->setRefundedAmount(new Price('10', 'UAH'));
    $payment->setState('partially_refunded');
    $payment->save();
    $client = $this->createMock(NovaPayApiClientInterface::class);
    $client->expects(self::once())->method('voidPayment')->willReturn(new AcknowledgementResponse(200));
    $manager = $this->createRefundOperationManager($client);
    $manager->refundAmount($payment, $this->createRuntimeProvider(), '5');
    $payment = $this->reloadEntity($payment);
    self::assertInstanceOf(PaymentInterface::class, $payment);
    $manager->confirm($payment, NormalizedPostbackEvent::fromValues('stale-paid-money', 'processing_void', []), hash('sha256', 'processing-second'));
    foreach ([[], ['10']] as $amounts) {
      $manager->confirm($payment, NormalizedPostbackEvent::fromValues('stale-paid-money', 'paid', [], $amounts), hash('sha256', json_encode($amounts, JSON_THROW_ON_ERROR)));
      self::assertSame(0, $this->countRefundLedgerRows($payment));
      self::assertTrue($manager->canCheckStatus($payment));
      self::assertEquals(new Price('10', 'UAH'), $payment->getRefundedAmount());
    }
    $manager->confirm($payment, NormalizedPostbackEvent::fromValues('stale-paid-money', 'paid', [], ['15']), hash('sha256', 'second-confirmed'));
    self::assertEquals(new Price('15', 'UAH'), $payment->getRefundedAmount());
  }

  /**
   * Tests confirmed mixed modes in both orders with unchanged quantity intent.
   */
  public function testConfirmedMixedRefundModes(): void {
    $this->setQuantityStep('0.5');
    foreach ([TRUE, FALSE] as $money_first) {
      $session = 'mixed-' . (int) $money_first;
      $payment = $this->createCompletedPayment($session);
      $client = $this->createMock(NovaPayApiClientInterface::class);
      $client->expects(self::exactly(3))->method('voidPayment')->willReturn(new AcknowledgementResponse(200));
      $manager = $this->createRefundOperationManager($client);
      foreach ([$money_first, !$money_first] as $index => $money) {
        if ($money) {
          $manager->refundAmount($payment, $this->createRuntimeProvider(), '5');
        }
        else {
          $manager->refund($payment, $this->createRuntimeProvider(), [(int) $this->order->getItems()[0]->id() => '0.5']);
        }
        $payment = $this->reloadEntity($payment);
        self::assertInstanceOf(PaymentInterface::class, $payment);
        $cumulative = $index === 1 ? '20' : ($money ? '5' : '15');
        $manager->confirm($payment, NormalizedPostbackEvent::fromValues($session, 'paid', [], [$cumulative]), hash('sha256', $session . $index));
      }
      self::assertEquals(new Price('20', 'UAH'), $payment->getRefundedAmount());
      $quantities = $this->container->get('commerce_novapay.refund_ledger')->getRefundedQuantities((int) $payment->id());
      self::assertSame(0, Calculator::compare('0.5', $quantities[(int) $this->order->getItems()[0]->id()]));
      $manager->refundAmount($payment, $this->createRuntimeProvider(), '10');
      $payment = $this->reloadEntity($payment);
      self::assertInstanceOf(PaymentInterface::class, $payment);
      $manager->confirm($payment, NormalizedPostbackEvent::fromValues($session, 'voided', []), hash('sha256', $session . 'final'));
      self::assertEquals(new Price('30', 'UAH'), $payment->getRefundedAmount());
    }
  }

  /**
   * Tests the configurable virtual amount against confirmed ledger history.
   */
  public function testVirtualOrderItemRefundAmount(): void {
    $this->container->get('current_user')->setAccount(User::create(['uid' => 1]));
    $payment = $this->createCompletedPayment('virtual-item-amount');
    $item = $this->order->getItems()[0];
    $builder = $this->container->get(OrderItemRefundAmountDisplay::class);
    self::assertSame('0', $builder->build($item)['#plain_text']);
    $ledger = $this->container->get('commerce_novapay.refund_ledger');
    $ledger->recordConfirmed((int) $payment->id(), 'virtual-first', [
      new RefundSelection((int) $item->id(), '0', new Price('1.25', 'UAH')),
      new RefundSelection(NULL, '0', new Price('5', 'UAH')),
    ]);
    $ledger->recordConfirmed((int) $payment->id(), 'virtual-second', [
      new RefundSelection((int) $item->id(), '0', new Price('2.50', 'UAH')),
    ]);
    $expected = $this->container->get('commerce_price.currency_formatter')->format('3.75', 'UAH');
    $build = $builder->build($item);
    self::assertSame($expected, $build['#plain_text']);
    self::assertSame(0, $build['#cache']['max-age']);
    $extras = $this->container->get('entity_field.manager')->getExtraFields('commerce_order_item', 'test');
    self::assertArrayHasKey('novapay_refunded_amount', $extras['display']);
    $display = EntityViewDisplay::collectRenderDisplay($item, 'default');
    self::assertNull($display->getComponent('novapay_refunded_amount'));
    $display->setComponent('novapay_refunded_amount', ['weight' => 20])->save();
    $view_builder = $this->container->get('entity_type.manager')->getViewBuilder('commerce_order_item');
    self::assertInstanceOf(EntityViewBuilder::class, $view_builder);
    $view = $view_builder->build($view_builder->view($item));
    self::assertSame($expected, $view['novapay_refunded_amount']['#plain_text']);
    $definition = $this->container->get('views.views_data')->get('commerce_order_item');
    self::assertSame('commerce_novapay_order_item_refunded_amount', $definition['novapay_refunded_amount']['field']['id']);
    $amount_view = View::create([
      'id' => 'test_refunded_amount',
      'base_table' => 'commerce_order_item',
      'display' => [
        'default' => [
          'display_plugin' => 'default',
          'id' => 'default',
          'display_options' => [
            'fields' => [
              'novapay_refunded_amount' => [
                'id' => 'novapay_refunded_amount',
                'table' => 'commerce_order_item',
                'field' => 'novapay_refunded_amount',
                'plugin_id' => 'commerce_novapay_order_item_refunded_amount',
              ],
            ],
          ],
        ],
      ],
    ])->getExecutable();
    $amount_view->execute();
    self::assertCount(1, $amount_view->result);
    $rendered = $this->container->get('renderer')->executeInRenderContext(
      new RenderContext(),
      fn () => $amount_view->field['novapay_refunded_amount']->render($amount_view->result[0]),
    );
    self::assertSame($expected, (string) $rendered);
    $field = $amount_view->field['novapay_refunded_amount'];
    $field->options['hide_empty'] = TRUE;
    $field->options['empty_zero'] = TRUE;
    $rendered = $this->container->get('renderer')->executeInRenderContext(
      new RenderContext(),
      fn () => $field->advancedRender($amount_view->result[0]),
    );
    self::assertSame($expected, (string) $rendered);
    $this->container->get('database')->delete('commerce_novapay_refund_ledger')
      ->condition('order_item_id', (int) $item->id())->execute();
    self::assertSame('0', $builder->build($item)['#plain_text']);
    $zero = $this->container->get('renderer')->executeInRenderContext(
      new RenderContext(),
      fn () => $field->render($amount_view->result[0]),
    );
    self::assertSame('0', $zero);
    $rendered = $this->container->get('renderer')->executeInRenderContext(
      new RenderContext(),
      fn () => $field->advancedRender($amount_view->result[0]),
    );
    self::assertSame('', (string) $rendered);
    $this->container->get('current_user')->setAccount(new AnonymousUserSession());
    $this->container->get('entity_type.manager')->getAccessControlHandler('commerce_order_item')->resetCache();
    self::assertArrayNotHasKey('#plain_text', $builder->build($item));
  }

  /**
   * Tests item-only refund UI and rejects forged monetary-mode submissions.
   */
  public function testRefundFormDisablesArbitraryAmount(): void {
    $payment = $this->createCompletedPayment('item-only-form');
    $plugin_form = new NovaPayRefundForm();
    $plugin_form->setEntity($payment);
    $plugin_form->setPlugin($this->paymentGateway->getPlugin());
    $state = new FormState();
    $form = $plugin_form->buildConfigurationForm(['#parents' => ['payment']], $state);
    self::assertSame('value', $form['refund_mode']['#type']);
    self::assertSame('items', $form['refund_mode']['#value']);
    self::assertArrayNotHasKey('refund_amount', $form);
    self::assertArrayHasKey('items', $form);
    foreach ($this->order->getItems() as $item) {
      self::assertArrayNotHasKey('refunded', $form['items'][$item->id()]);
      self::assertArrayNotHasKey('refunded_amount', $form['items'][$item->id()]);
    }
    $state->setValues(['payment' => ['refund_mode' => 'amount', 'refund_amount' => '1']]);
    $form['refund_mode']['#parents'] = ['payment', 'refund_mode'];
    $plugin_form->validateConfigurationForm($form, $state);
    self::assertCount(1, $state->getErrors());
    $state->clearErrors();
    $this->expectException(InvalidRequestException::class);
    $plugin_form->submitConfigurationForm($form, $state);
  }

  /**
   * Tests full quantity intent after capturing less than the order item value.
   */
  public function testFullQuantityUsesCapturedBalance(): void {
    $payment = $this->createCompletedPayment('captured-full-items');
    $payment->setAmount(new Price('12', 'UAH'))->save();
    $item_id = (int) $this->order->getItems()[0]->id();
    $api = $this->createMock(NovaPayApiClientInterface::class);
    $api->expects(self::once())->method('voidPayment')
      ->with(self::anything(), self::callback(static fn (VoidRequest $request): bool =>
        $request->toArray() === ['session_id' => 'captured-full-items']))
      ->willReturn(new AcknowledgementResponse(200));
    $this->createRefundOperationManager($api)->refund($payment, $this->createRuntimeProvider(), [$item_id => '1']);
    $payment = $this->reloadEntity($payment);
    self::assertInstanceOf(PaymentInterface::class, $payment);
    self::assertSame('12', $payment->get('novapay_pending_amount')->getString());
    self::assertSame('completed', $payment->getState()->getId());
    self::assertTrue($payment->getRefundedAmount()->isZero());
  }

  /**
   * Tests all remaining quantities after an already confirmed partial refund.
   */
  public function testFullRemainingQuantitiesAfterPartialRefund(): void {
    $this->setQuantityStep('0.5');
    $payment = $this->createCompletedPayment('remaining-full-items');
    $item_id = (int) $this->order->getItems()[0]->id();
    $requests = [];
    $api = $this->createMock(NovaPayApiClientInterface::class);
    $api->method('voidPayment')->willReturnCallback(
      static function (RuntimeConfigurationProviderInterface $gateway, VoidRequest $request) use (&$requests): AcknowledgementResponse {
        $requests[] = $request->toArray();
        return new AcknowledgementResponse(200);
      },
    );
    $manager = $this->createRefundOperationManager($api);
    $manager->refund($payment, $this->createRuntimeProvider(), [$item_id => '0.5']);
    $payment = $this->reloadEntity($payment);
    self::assertInstanceOf(PaymentInterface::class, $payment);
    $manager->confirm($payment, NormalizedPostbackEvent::fromValues('remaining-full-items', 'paid', [], ['15']), hash('sha256', 'remaining-partial'));
    $payment = $this->reloadEntity($payment);
    self::assertInstanceOf(PaymentInterface::class, $payment);
    self::assertSame('partially_refunded', $payment->getState()->getId());
    self::assertEquals(new Price('15', 'UAH'), $payment->getBalance());
    $manager->refund($payment, $this->createRuntimeProvider(), [$item_id => '0.5']);
    self::assertCount(2, $requests);
    self::assertSame(['session_id' => 'remaining-full-items'], $requests[1]);
    $payment = $this->reloadEntity($payment);
    self::assertInstanceOf(PaymentInterface::class, $payment);
    self::assertSame('15', $payment->get('novapay_pending_amount')->getString());
    self::assertSame('partially_refunded', $payment->getState()->getId());
    self::assertEquals(new Price('15', 'UAH'), $payment->getRefundedAmount());
  }

  /**
   * Tests optional quantities, malformed rows and captured-balance validation.
   */
  public function testItemRefundFormValidation(): void {
    $payment = $this->createCompletedPayment('quantity-validation');
    $payment->setAmount(new Price('20', 'UAH'))->save();
    $item_id = (int) $this->order->getItems()[0]->id();
    $this->setQuantityStep('0.5');
    $plugin_form = new NovaPayRefundForm();
    $plugin_form->setEntity($payment);
    $plugin_form->setPlugin($this->paymentGateway->getPlugin());
    $form = $plugin_form->buildConfigurationForm(['#parents' => ['payment']], new FormState());
    $form['items']['#parents'] = ['payment', 'items'];
    $form['refund_mode']['#parents'] = ['payment', 'refund_mode'];
    $form['items'][$item_id]['quantity']['#parents'] = ['payment', 'items', $item_id, 'quantity'];
    foreach (['', '0', '0.00', '0.5'] as $quantity) {
      $state = new FormState();
      $state->setValue('payment', [
        'refund_mode' => 'items',
        'items' => [$item_id => ['quantity' => $quantity]],
      ]);
      $plugin_form->validateConfigurationForm($form, $state);
      self::assertSame([], $state->getErrors());
    }
    self::assertSame('number', $form['items'][$item_id]['amount']['#type']);
    foreach (['0', '0.00', ''] as $amount) {
      $state = new FormState();
      $state->setValue('payment', [
        'refund_mode' => 'items',
        'items' => [$item_id => ['quantity' => '0', 'amount' => $amount]],
      ]);
      $plugin_form->validateConfigurationForm($form, $state);
      self::assertSame([], $state->getErrors());
    }
    foreach (['0', '1'] as $quantity) {
      $state = new FormState();
      $state->setValue('payment', [
        'refund_mode' => 'items',
        'items' => [$item_id => ['quantity' => $quantity, 'amount' => '2']],
      ]);
      $plugin_form->validateConfigurationForm($form, $state);
      self::assertSame([], $state->getErrors());
    }
    foreach ([
      [$item_id => ['quantity' => '1']],
      [$item_id => ['quantity' => '0', 'amount' => '21']],
      [$item_id => ['quantity' => '0', 'amount' => []]],
      [$item_id => ['quantity' => '-1']],
      [$item_id => ['quantity' => '2']],
      [$item_id => ['quantity' => '0.25']],
      [$item_id => ['quantity' => '1e0']],
      [$item_id => ['quantity' => NULL]],
      [$item_id => ['quantity' => []]],
      [$item_id + 1000 => ['quantity' => '0']],
      [$item_id => 'malformed'],
      NULL,
    ] as $rows) {
      $state = new FormState();
      $state->setValue('payment', ['refund_mode' => 'items', 'items' => $rows]);
      $plugin_form->validateConfigurationForm($form, $state);
      self::assertNotSame([], $state->getErrors());
      $state->clearErrors();
    }
    // An otherwise valid partial quantity cannot exceed the captured balance.
    $payment->setAmount(new Price('10', 'UAH'))->save();
    $state = new FormState();
    $state->setValue('payment', [
      'refund_mode' => 'items',
      'items' => [$item_id => ['quantity' => '0.5']],
    ]);
    $plugin_form->validateConfigurationForm($form, $state);
    self::assertNotSame([], $state->getErrors());
    $state->clearErrors();
  }

  /**
   * Creates a persisted completed payment for refund operation tests.
   */
  private function createCompletedPayment(string $session_id): PaymentInterface {
    $payment_storage = $this->container
      ->get('entity_type.manager')
      ->getStorage('commerce_payment');
    $payment = $payment_storage->create([
      'payment_gateway' => $this->paymentGateway,
      'order_id' => $this->order->id(),
      'amount' => new Price('30', 'UAH'),
      'state' => 'completed',
      'remote_id' => $session_id,
      'remote_state' => 'paid',
      'novapay_operation_id' => 'operation-id',
      'novapay_external_id' => (string) $this->order->getOrderNumber(),
    ]);
    self::assertInstanceOf(PaymentInterface::class, $payment);
    $payment->save();
    return $payment;
  }

  /**
   * Configures the quantity widget for the tested order item type.
   */
  private function setQuantityStep(string $step): void {
    $bundle = $this->order->getItems()[0]->bundle();
    $display_id = 'commerce_order_item.' . $bundle . '.default';
    $display = EntityFormDisplay::load($display_id)
      ?? EntityFormDisplay::create([
        'id' => $display_id,
        'targetEntityType' => 'commerce_order_item',
        'bundle' => $bundle,
        'mode' => 'default',
        'status' => TRUE,
      ]);
    self::assertInstanceOf(EntityFormDisplay::class, $display);
    $component = $display->getComponent('quantity') ?? [
      'type' => 'commerce_quantity',
      'weight' => 1,
      'region' => 'content',
      'settings' => ['placeholder' => ''],
      'third_party_settings' => [],
    ];
    $component['settings']['step'] = $step;
    $display->setComponent('quantity', $component)->save();
  }

  /**
   * Creates the refund manager with a mocked one-shot API boundary.
   */
  private function createRefundOperationManager(
    NovaPayApiClientInterface $api_client,
    ?LockBackendInterface $lock = NULL,
  ): RefundOperationManager {
    return new RefundOperationManager(
      $this->container->get('commerce_novapay.refund_payment_storage'),
      $api_client,
      $lock ?? $this->container->get('lock'),
      $this->container->get('commerce_novapay.refund_selection_builder'),
      $this->container->get('commerce_novapay.pending_refund_storage'),
      $this->container->get('commerce_novapay.refund_confirmation_manager'),
    );
  }

  /**
   * Counts confirmed ledger rows for one payment.
   */
  private function countRefundLedgerRows(PaymentInterface $payment): int {
    return (int) $this->container->get('database')
      ->select('commerce_novapay_refund_ledger', 'refund')
      ->condition('payment_id', $payment->id())
      ->countQuery()
      ->execute()
      ->fetchField();
  }

  /**
   * Creates the tested manager with a mocked financial boundary.
   */
  private function createAuthorizationOperationManager(
    NovaPayApiClientInterface $api_client,
    ?LockBackendInterface $lock = NULL,
  ): AuthorizationOperationManager {
    return new AuthorizationOperationManager(
      $this->container->get('entity_type.manager'),
      $api_client,
      $lock ?? $this->container->get('lock'),
    );
  }

  /**
   * Creates a status manager with a mocked read-only API boundary.
   */
  private function createPaymentStatusCheckManager(
    NovaPayApiClientInterface $api_client,
  ): PaymentStatusCheckManager {
    return new PaymentStatusCheckManager(
      $this->container->get('entity_type.manager'),
      $api_client,
      $this->container->get('commerce_novapay.postback.status_mapper'),
      $this->container->get('lock'),
      $this->container->get('database'),
      $this->container->get('commerce_novapay.payment_order_lock'),
    );
  }

  /**
   * Asserts that an ambiguous void result remains blocked for postback.
   */
  private function assertUncertainVoidFailureRetainsMarker(
    \Throwable $failure,
    string $session_id,
  ): void {
    $payment = $this->createAuthorizationPayment($session_id);
    $api_client = $this->createMock(NovaPayApiClientInterface::class);
    $api_client->expects(self::once())
      ->method('voidPayment')
      ->willThrowException($failure);
    $manager = $this->createAuthorizationOperationManager($api_client);

    try {
      $manager->void($payment, $this->createRuntimeProvider());
      self::fail('An uncertain remote result must fail the form request.');
    }
    catch (PaymentGatewayException $exception) {
      self::assertStringContainsString('uncertain', $exception->getMessage());
    }

    $payment = $this->reloadEntity($payment);
    self::assertInstanceOf(PaymentInterface::class, $payment);
    self::assertSame(
      'void',
      $payment->get('novapay_pending_operation')->getString(),
    );
    self::assertFalse($manager->canVoid($payment));
  }

  /**
   * Creates a non-sensitive runtime provider for operation payloads.
   */
  private function createRuntimeProvider(): RuntimeConfigurationProviderInterface {
    $configuration = new RuntimeConfiguration(
      new RuntimeProfile(
        NovaPayMode::Test,
        NULL,
        TransactionMode::Hold,
        '31316718',
        FALSE,
      ),
      new Credentials(NovaPayMode::Test, '2', 'private', 'public'),
    );
    $provider = $this->createMock(
      RuntimeConfigurationProviderInterface::class,
    );
    $provider->method('getRuntimeConfiguration')->willReturn($configuration);
    return $provider;
  }

}
