<?php

declare(strict_types=1);

namespace Drupal\Tests\commerce_novapay\Unit\Postback;

use Drupal\commerce_novapay\Payment\PaymentOrderLockInterface;
use Drupal\commerce_novapay\Api\NovaPayApiClientInterface;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\commerce_novapay\Credential\Credentials;
use Drupal\commerce_novapay\Credential\NovaPayMode;
use Drupal\commerce_novapay\Credential\RsaKeyValidator;
use Drupal\commerce_novapay\Signature\RsaSha256Verifier;
use Drupal\commerce_novapay\Signature\RsaSha1SandboxVerifier;
use Drupal\commerce_novapay\Exception\InvalidPostbackException;
use Drupal\commerce_novapay\Payment\SessionLockName;
use Drupal\commerce_novapay\Payment\RefundOperationManagerInterface;
use Drupal\commerce_novapay\Postback\Dto\NormalizedPostbackEvent;
use Drupal\commerce_novapay\Postback\Dto\ParsedPostback;
use Drupal\commerce_novapay\Postback\NovaPayStatus;
use Drupal\commerce_novapay\Postback\Parser\PostbackParserInterface;
use Drupal\commerce_novapay\Postback\Parser\PostbackParser;
use Drupal\commerce_novapay\Postback\Parser\V1PostbackParser;
use Drupal\commerce_novapay\Postback\Parser\V2PostbackParser;
use Drupal\commerce_novapay\Postback\PaymentStatusMapperInterface;
use Drupal\commerce_novapay\Postback\PostbackEventRepositoryInterface;
use Drupal\commerce_novapay\Postback\PostbackOutcome;
use Drupal\commerce_novapay\Postback\PostbackProcessor;
use Drupal\commerce_novapay\Postback\PostbackVersion;
use Drupal\commerce_novapay\Runtime\RuntimeConfiguration;
use Drupal\commerce_novapay\Runtime\RuntimeConfigurationProviderInterface;
use Drupal\commerce_novapay\Runtime\RuntimeProfile;
use Drupal\commerce_novapay\Runtime\TransactionMode;
use Drupal\commerce_novapay\Signature\SandboxLegacyVerifierInterface;
use Drupal\commerce_novapay\Signature\VerifierInterface;
use Drupal\commerce_order\OrderStorageInterface;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_novapay\Postback\PostbackResult;
use Drupal\commerce_payment\Entity\PaymentGatewayInterface;
use Drupal\commerce_payment\Entity\PaymentInterface;
use Drupal\commerce_payment\PaymentStorageInterface;
use Drupal\state_machine\Plugin\Field\FieldType\StateItemInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Tests the signature-first postback processing boundary.
 */
#[CoversClass(PostbackProcessor::class)]
#[CoversClass(PostbackResult::class)]
#[Group('commerce_novapay')]
final class PostbackProcessorTest extends TestCase {

  /**
   * The exact-byte signature verifier.
   */
  private VerifierInterface&MockObject $verifier;

  /**
   * The test-environment-only legacy signature verifier.
   */
  private SandboxLegacyVerifierInterface&MockObject $sandboxLegacyVerifier;

  /**
   * The verified-body parser.
   */
  private PostbackParserInterface&MockObject $parser;

  /**
   * The entity type manager.
   */
  private EntityTypeManagerInterface&MockObject $entityTypeManager;

  /**
   * The Commerce payment status mapper.
   */
  private PaymentStatusMapperInterface&MockObject $statusMapper;

  /**
   * The atomic postback event journal.
   */
  private PostbackEventRepositoryInterface&MockObject $eventRepository;

  /**
   * The session-scoped lock backend.
   */
  private LockBackendInterface&MockObject $lock;

  /**
   * The postback-confirmed refund operation manager.
   */
  private RefundOperationManagerInterface&MockObject $refundManager;

  /**
   * The current NovaPay payment gateway.
   */
  private PaymentGatewayInterface&MockObject $gateway;

  /**
   * The runtime-aware gateway plugin.
   */
  private RuntimeConfigurationProviderInterface&MockObject $gatewayPlugin;

  /**
   * The processor under test.
   */
  private PostbackProcessor $processor;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->verifier = $this->createMock(VerifierInterface::class);
    $this->sandboxLegacyVerifier = $this->createMock(
      SandboxLegacyVerifierInterface::class,
    );
    $this->parser = $this->createMock(PostbackParserInterface::class);
    $this->entityTypeManager = $this->createMock(
      EntityTypeManagerInterface::class,
    );
    $this->statusMapper = $this->createMock(
      PaymentStatusMapperInterface::class,
    );
    $this->eventRepository = $this->createMock(
      PostbackEventRepositoryInterface::class,
    );
    $this->eventRepository->method('processOnce')->willReturnCallback(
      static fn (
        string $event_key,
        string $session_id,
        string $gateway_id,
        NovaPayStatus $status,
        callable $processor,
      ): PostbackOutcome => $processor(),
    );
    $this->lock = $this->createMock(LockBackendInterface::class);
    $this->lock->method('acquire')->willReturn(TRUE);
    $this->refundManager = $this->createMock(
      RefundOperationManagerInterface::class,
    );
    $this->gateway = $this->createMock(PaymentGatewayInterface::class);
    $this->gateway->method('id')->willReturn('novapay_test');
    $this->gatewayPlugin = $this->createMock(
      RuntimeConfigurationProviderInterface::class,
    );
    $this->gatewayPlugin->method('getRuntimeConfiguration')->willReturn(
      $this->createRuntimeConfiguration(),
    );
    $this->processor = new PostbackProcessor(
      $this->verifier,
      $this->sandboxLegacyVerifier,
      $this->parser,
      $this->entityTypeManager,
      $this->statusMapper,
      $this->eventRepository,
      $this->lock,
      $this->refundManager,
      $this->createMock(NovaPayApiClientInterface::class),
      $this->createMock(PaymentOrderLockInterface::class),
    );
  }

  /**
   * Tests that an invalid signature prevents parsing and entity access.
   */
  public function testInvalidSignatureDoesNotParseOrLoadEntities(): void {
    $this->verifier->expects(self::once())->method('verify')
      ->with('raw-body', 'invalid-signature', 'public-key')
      ->willReturn(FALSE);
    $this->sandboxLegacyVerifier->expects(self::once())->method('verify')
      ->with('raw-body', 'invalid-signature', 'public-key')
      ->willReturn(FALSE);
    $this->parser->expects(self::never())->method('parse');
    $this->entityTypeManager->expects(self::never())->method('getStorage');
    $this->statusMapper->expects(self::never())->method('apply');

    $result = $this->processor->process(
      $this->gateway,
      $this->gatewayPlugin,
      'raw-body',
      'invalid-signature',
    );

    self::assertSame(PostbackOutcome::InvalidSignature, $result->getOutcome());
  }

  /**
   * Tests the explicitly test-only fallback for real sandbox callbacks.
   */
  public function testSandboxAcceptsVerifiedLegacySignature(): void {
    $this->verifier->expects(self::once())->method('verify')
      ->willReturn(FALSE);
    $this->sandboxLegacyVerifier->expects(self::once())->method('verify')
      ->willReturn(TRUE);
    $this->parser->method('parse')->willReturn($this->createParsedPostback());
    $payment = $this->createMock(PaymentInterface::class);
    $payment_storage = $this->createMock(PaymentStorageInterface::class);
    $payment_storage->method('loadByProperties')->willReturn([$payment]);
    $this->entityTypeManager->method('getStorage')
      ->with('commerce_payment')->willReturn($payment_storage);
    $this->statusMapper->expects(self::once())->method('apply')
      ->with($payment, NovaPayStatus::Holded)->willReturn(TRUE);

    $result = $this->processor->process(
      $this->gateway,
      $this->gatewayPlugin,
      'legacy-sandbox-json',
      'legacy-sandbox-signature',
    );

    self::assertSame(PostbackOutcome::Applied, $result->getOutcome());
  }

  /**
   * Tests that production never attempts the legacy SHA-1 verifier.
   */
  public function testLiveModeRejectsLegacySignature(): void {
    $live_gateway_plugin = $this->createMock(
      RuntimeConfigurationProviderInterface::class,
    );
    $live_gateway_plugin->method('getRuntimeConfiguration')->willReturn(
      $this->createRuntimeConfiguration(NovaPayMode::Live),
    );
    $this->verifier->expects(self::once())->method('verify')
      ->willReturn(FALSE);
    $this->sandboxLegacyVerifier->expects(self::never())->method('verify');
    $this->parser->expects(self::never())->method('parse');
    $this->entityTypeManager->expects(self::never())->method('getStorage');

    $result = $this->processor->process(
      $this->gateway,
      $live_gateway_plugin,
      'legacy-live-json',
      'legacy-live-signature',
    );

    self::assertSame(PostbackOutcome::InvalidSignature, $result->getOutcome());
  }

  /**
   * Tests that a signed v1 payload is unavailable outside sandbox mode.
   */
  public function testLiveModeRejectsV1Payload(): void {
    $live_gateway_plugin = $this->createMock(
      RuntimeConfigurationProviderInterface::class,
    );
    $live_gateway_plugin->method('getRuntimeConfiguration')->willReturn(
      $this->createRuntimeConfiguration(NovaPayMode::Live),
    );
    $this->verifier->expects(self::once())->method('verify')->willReturn(TRUE);
    $this->sandboxLegacyVerifier->expects(self::never())->method('verify');
    $this->parser->expects(self::once())->method('parse')->willReturn(
      $this->createParsedPostback(
        NovaPayStatus::Paid,
        PostbackVersion::V1,
      ),
    );
    $this->entityTypeManager->expects(self::never())->method('getStorage');
    $this->eventRepository->expects(self::never())->method('processOnce');

    $result = $this->processor->process(
      $this->gateway,
      $live_gateway_plugin,
      'signed-v1-live-json',
      'valid-signature',
    );

    self::assertSame(PostbackOutcome::InvalidPayload, $result->getOutcome());
  }

  /**
   * Tests invalid custom signatures fail before parsing after both checks.
   */
  public function testCustomSandboxRejectsInvalidSignatures(): void {
    $plugin = $this->createMock(RuntimeConfigurationProviderInterface::class);
    $plugin->method('getRuntimeConfiguration')->willReturn(
      $this->createRuntimeConfiguration(custom_test: TRUE),
    );
    $this->verifier->expects(self::once())->method('verify')
      ->with('body', 'signature', 'public-key')->willReturn(FALSE);
    $this->sandboxLegacyVerifier->expects(self::once())->method('verify')
      ->with('body', 'signature', 'public-key')->willReturn(FALSE);
    $this->parser->expects(self::never())->method('parse');
    $this->entityTypeManager->expects(self::never())->method('getStorage');
    $result = $this->processor->process($this->gateway, $plugin, 'body', 'signature');
    self::assertSame(PostbackOutcome::InvalidSignature, $result->getOutcome());
  }

  /**
   * Tests custom callback signatures, V1/V2, Live isolation and tampering.
   */
  #[DataProvider('customSha1Provider')]
  public function testCustomSha1Boundary(NovaPayMode $mode, bool $tampered, PostbackOutcome $outcome, bool $v1 = FALSE, int $algorithm = OPENSSL_ALGO_SHA1): void {
    $key = openssl_pkey_new(['private_key_bits' => 2048]);
    self::assertNotFalse($key);
    $details = openssl_pkey_get_details($key);
    self::assertIsArray($details);
    $body = '{"id":"session-uuid","status":"paid","payments":[{"external_id":"42","amount":10}]}';
    if ($v1) {
      $body = '{"id":"session-uuid","status":"holded","external_id":"42","amount":25,"paytype":"google_pay"}';
    }
    self::assertTrue(openssl_sign($body, $signature, $key, $algorithm));
    $plugin = $this->createMock(RuntimeConfigurationProviderInterface::class);
    $plugin->method('getRuntimeConfiguration')->willReturn(new RuntimeConfiguration(
      new RuntimeProfile($mode, 'live-merchant', TransactionMode::Direct, '', FALSE, 5, TRUE, 'test-merchant'),
      new Credentials($mode, 'merchant', 'unused-private', $details['key']),
    ));
    $accepted = $outcome === PostbackOutcome::Applied;
    $payment_storage = $this->createMock(PaymentStorageInterface::class);
    $payment = $this->createMock(PaymentInterface::class);
    $payment_storage->method('loadByProperties')->willReturn([$payment]);
    $this->entityTypeManager->expects($accepted ? self::once() : self::never())
      ->method('getStorage')->with('commerce_payment')->willReturn($payment_storage);
    $this->statusMapper->expects($accepted ? self::once() : self::never())
      ->method('apply')->with($payment, $v1 ? NovaPayStatus::Holded : NovaPayStatus::Paid)->willReturn(TRUE);
    $validator = new RsaKeyValidator();
    $processor = new PostbackProcessor(
      new RsaSha256Verifier($validator),
      new RsaSha1SandboxVerifier($validator),
      new PostbackParser(
        new V1PostbackParser(),
        new V2PostbackParser(),
      ),
      $this->entityTypeManager,
      $this->statusMapper,
      $this->eventRepository,
      $this->lock,
      $this->refundManager,
      $this->createMock(NovaPayApiClientInterface::class),
      $this->createMock(PaymentOrderLockInterface::class),
    );
    $result = $processor->process($this->gateway, $plugin, $tampered ? $body . ' ' : $body, base64_encode($signature));
    self::assertSame($outcome, $result->getOutcome());
  }

  /**
   * Supplies environment and raw-body integrity boundaries.
   *
   * @return array<string, array{NovaPayMode, bool, PostbackOutcome, 3?: bool, 4?: int}>
   *   Runtime mode, tampering flag and expected outcome.
   */
  public static function customSha1Provider(): array {
    return [
      'custom test v2 accepted' => [NovaPayMode::Test, FALSE, PostbackOutcome::Applied],
      'live sha1 rejected' => [NovaPayMode::Live, FALSE, PostbackOutcome::InvalidSignature],
      'tampered custom rejected' => [NovaPayMode::Test, TRUE, PostbackOutcome::InvalidSignature],
      'custom test v1 SHA256 accepted' => [
        NovaPayMode::Test, FALSE, PostbackOutcome::Applied, TRUE, OPENSSL_ALGO_SHA256,
      ],
      'custom test v1 SHA1 accepted' => [NovaPayMode::Test, FALSE, PostbackOutcome::Applied, TRUE],
      'live v1 SHA256 rejected' => [
        NovaPayMode::Live, FALSE, PostbackOutcome::InvalidPayload, TRUE, OPENSSL_ALGO_SHA256,
      ],
      'live v1 SHA1 rejected' => [NovaPayMode::Live, FALSE, PostbackOutcome::InvalidSignature, TRUE],
      'tampered v1 SHA256 rejected' => [
        NovaPayMode::Test, TRUE, PostbackOutcome::InvalidSignature, TRUE, OPENSSL_ALGO_SHA256,
      ],
      'tampered v1 SHA1 rejected' => [NovaPayMode::Test, TRUE, PostbackOutcome::InvalidSignature, TRUE],
    ];
  }

  /**
   * Tests that the documented public sandbox v1 shape remains processable.
   */
  public function testTestModeAcceptsV1Payload(): void {
    $this->verifier->expects(self::once())->method('verify')->willReturn(TRUE);
    $this->parser->expects(self::once())->method('parse')->willReturn(
      $this->createParsedPostback(
        NovaPayStatus::Paid,
        PostbackVersion::V1,
      ),
    );
    $payment = $this->createMock(PaymentInterface::class);
    $payment_storage = $this->createMock(PaymentStorageInterface::class);
    $payment_storage->method('loadByProperties')->willReturn([$payment]);
    $this->entityTypeManager->method('getStorage')
      ->with('commerce_payment')->willReturn($payment_storage);
    $this->statusMapper->expects(self::once())->method('apply')
      ->with($payment, NovaPayStatus::Paid)->willReturn(TRUE);

    $result = $this->processor->process(
      $this->gateway,
      $this->gatewayPlugin,
      'signed-v1-sandbox-json',
      'valid-signature',
    );

    self::assertSame(PostbackOutcome::Applied, $result->getOutcome());
    self::assertSame(PostbackVersion::V1, $result->getVersion());
    self::assertSame(NovaPayStatus::Paid, $result->getStatus());
  }

  /**
   * Tests that unsupported verified JSON is reported without entity access.
   */
  public function testUnsupportedSchemaDoesNotLoadEntities(): void {
    $this->verifier->method('verify')->willReturn(TRUE);
    $this->parser->method('parse')->willThrowException(
      InvalidPostbackException::unsupportedSchema(),
    );
    $this->entityTypeManager->expects(self::never())->method('getStorage');

    $result = $this->processor->process(
      $this->gateway,
      $this->gatewayPlugin,
      'unsupported-json',
      'valid-signature',
    );

    self::assertSame(PostbackOutcome::InvalidPayload, $result->getOutcome());
  }

  /**
   * Tests that an unknown session is acknowledged without payment mutation.
   */
  public function testUnknownSessionDoesNotChangePayment(): void {
    $this->verifier->method('verify')->willReturn(TRUE);
    $this->parser->method('parse')->willReturn($this->createParsedPostback());
    $payment_storage = $this->createMock(PaymentStorageInterface::class);
    $payment_storage->method('loadByProperties')->willReturn([]);
    $order_storage = $this->createMock(OrderStorageInterface::class);
    $this->entityTypeManager->method('getStorage')->willReturnMap([
      ['commerce_payment', $payment_storage],
      ['commerce_order', $order_storage],
    ]);
    $this->statusMapper->expects(self::never())->method('apply');

    $result = $this->processor->process(
      $this->gateway,
      $this->gatewayPlugin,
      'verified-json',
      'valid-signature',
    );

    self::assertSame(PostbackOutcome::UnknownPayment, $result->getOutcome());
    self::assertSame(PostbackVersion::V2, $result->getVersion());
    self::assertSame(NovaPayStatus::Holded, $result->getStatus());
  }

  /**
   * Tests application to a unique same-gateway session payment.
   */
  public function testAppliesVerifiedNormalizedEvent(): void {
    $this->verifier->method('verify')->willReturn(TRUE);
    $parsed = $this->createParsedPostback();
    $this->parser->method('parse')->willReturn($parsed);
    $payment = $this->createMock(PaymentInterface::class);
    $payment_storage = $this->createMock(PaymentStorageInterface::class);
    $payment_storage->expects(self::once())->method('loadByProperties')
      ->with([
        'payment_gateway' => 'novapay_test',
        'remote_id' => 'session-uuid',
      ])
      ->willReturn([1 => $payment]);
    $this->entityTypeManager->method('getStorage')
      ->with('commerce_payment')->willReturn($payment_storage);
    $this->statusMapper->expects(self::once())->method('apply')
      ->with($payment, NovaPayStatus::Holded)->willReturn(TRUE);
    $this->refundManager->expects(self::once())->method('confirm')
      ->with(
        $payment,
        $parsed->getEvent(),
        hash('sha256', 'verified-json'),
      );

    $result = $this->processor->process(
      $this->gateway,
      $this->gatewayPlugin,
      'verified-json',
      'valid-signature',
    );

    self::assertSame(PostbackOutcome::Applied, $result->getOutcome());
  }

  /**
   * Tests safe diagnostics distinguish paid payments from paid orders.
   */
  #[DataProvider('paidOrderDiagnosticsProvider')]
  public function testAppliedPaymentDiagnostics(?bool $order_paid, string $expected): void {
    $this->verifier->method('verify')->willReturn(TRUE);
    $this->parser->method('parse')->willReturn($this->createParsedPostback(NovaPayStatus::Paid, PostbackVersion::V1));
    $payment = $this->createMock(PaymentInterface::class);
    $state = $this->createMock(StateItemInterface::class);
    $state->method('getId')->willReturn('completed');
    $payment->method('getState')->willReturn($state);
    $payment->method('getRemoteState')->willReturn('paid');
    $payment->method('isCompleted')->willReturn(TRUE);
    $order = $this->createMock(OrderInterface::class);
    if ($order_paid === NULL) {
      $order->method('isPaid')->willThrowException(new \RuntimeException('Sensitive diagnostic failure text'));
    }
    else {
      $order->method('isPaid')->willReturn($order_paid);
    }
    $payment->method('getOrder')->willReturn($order);
    $storage = $this->createMock(PaymentStorageInterface::class);
    $storage->method('loadByProperties')->willReturn([$payment]);
    $this->entityTypeManager->method('getStorage')->willReturn($storage);
    $this->statusMapper->method('apply')->willReturn(TRUE);
    $gateway_plugin = $this->createMock(RuntimeConfigurationProviderInterface::class);
    $gateway_plugin->method('getRuntimeConfiguration')->willReturn($this->createRuntimeConfiguration(NovaPayMode::Test, TRUE));

    $result = $this->processor->process($this->gateway, $gateway_plugin, 'signed-test-body', 'test-signature');

    self::assertSame(PostbackOutcome::Applied, $result->getOutcome());
    self::assertSame([
      'payment_state' => 'completed',
      'remote_state' => 'paid',
      'pending_operation' => 'none_or_other',
      'payment_completed' => 'yes',
      'order_paid' => $expected,
    ], $result->getDiagnostics());
  }

  /**
   * Provides paid, unpaid and unavailable order diagnostics.
   *
   * @return iterable<string, array{bool|null, string}>
   *   Order paid flag and the bounded diagnostic value.
   */
  public static function paidOrderDiagnosticsProvider(): iterable {
    yield 'paid order' => [TRUE, 'yes'];
    yield 'unpaid order' => [FALSE, 'no'];
    yield 'diagnostic failure' => [NULL, 'unavailable'];
  }

  /**
   * Tests that a terminal session void reaches the payment status mapper.
   */
  public function testSessionVoidedEventMapsPendingPartialRefund(): void {
    $this->verifier->method('verify')->willReturn(TRUE);
    $parsed = $this->createParsedPostback(NovaPayStatus::Voided);
    $this->parser->method('parse')->willReturn($parsed);
    $payment = $this->createMock(PaymentInterface::class);
    $payment->method('hasField')->willReturn(FALSE);
    $payment_storage = $this->createMock(PaymentStorageInterface::class);
    $payment_storage->method('loadByProperties')->willReturn([$payment]);
    $this->entityTypeManager->method('getStorage')
      ->with('commerce_payment')->willReturn($payment_storage);
    $this->refundManager->expects(self::never())->method('hasPendingPartialRefund');
    $this->refundManager->expects(self::once())->method('confirm');
    $this->statusMapper->expects(self::once())->method('apply')
      ->with($payment, NovaPayStatus::Voided)->willReturn(TRUE);

    $result = $this->processor->process(
      $this->gateway,
      $this->gatewayPlugin,
      'partial-voided-json',
      'valid-signature',
    );

    self::assertSame(PostbackOutcome::Applied, $result->getOutcome());
  }

  /**
   * Tests that a valid stale status is acknowledged without claiming mutation.
   */
  public function testIgnoredNormalizedEventIsReportedAccurately(): void {
    $this->verifier->method('verify')->willReturn(TRUE);
    $this->parser->method('parse')->willReturn($this->createParsedPostback());
    $payment = $this->createMock(PaymentInterface::class);
    $payment_storage = $this->createMock(PaymentStorageInterface::class);
    $payment_storage->method('loadByProperties')->willReturn([$payment]);
    $this->entityTypeManager->method('getStorage')
      ->with('commerce_payment')->willReturn($payment_storage);
    $this->statusMapper->expects(self::once())->method('apply')
      ->with($payment, NovaPayStatus::Holded)->willReturn(FALSE);

    $result = $this->processor->process(
      $this->gateway,
      $this->gatewayPlugin,
      'ignored-json',
      'valid-signature',
    );

    self::assertSame(PostbackOutcome::Ignored, $result->getOutcome());
  }

  /**
   * Tests that a stale created event is reported as ignored.
   */
  public function testCreatedEventForFailedPaymentIsIgnored(): void {
    $this->verifier->method('verify')->willReturn(TRUE);
    $this->parser->method('parse')->willReturn(
      $this->createParsedPostback(NovaPayStatus::Created),
    );
    $payment = $this->createMock(PaymentInterface::class);
    $payment_storage = $this->createMock(PaymentStorageInterface::class);
    $payment_storage->method('loadByProperties')->willReturn([$payment]);
    $this->entityTypeManager->method('getStorage')
      ->with('commerce_payment')->willReturn($payment_storage);
    $this->statusMapper->expects(self::once())->method('apply')
      ->with($payment, NovaPayStatus::Created)->willReturn(FALSE);

    $result = $this->processor->process(
      $this->gateway,
      $this->gatewayPlugin,
      'stale-created-json',
      'valid-signature',
    );

    self::assertSame(PostbackOutcome::Ignored, $result->getOutcome());
  }

  /**
   * Tests that an identical raw payload is acknowledged without entity access.
   */
  public function testDuplicatePayloadDoesNotChangePayment(): void {
    $this->verifier->method('verify')->willReturn(TRUE);
    $this->parser->method('parse')->willReturn($this->createParsedPostback());
    $this->eventRepository = $this->createMock(
      PostbackEventRepositoryInterface::class,
    );
    $this->eventRepository->expects(self::once())->method('processOnce')
      ->with(
        hash('sha256', 'same-raw-json'),
        'session-uuid',
        'novapay_test',
        NovaPayStatus::Holded,
        self::isInstanceOf(\Closure::class),
      )
      ->willReturn(NULL);
    $this->processor = new PostbackProcessor(
      $this->verifier,
      $this->sandboxLegacyVerifier,
      $this->parser,
      $this->entityTypeManager,
      $this->statusMapper,
      $this->eventRepository,
      $this->lock,
      $this->refundManager,
      $this->createMock(NovaPayApiClientInterface::class),
      $this->createMock(PaymentOrderLockInterface::class),
    );
    $storage = $this->createMock(PaymentStorageInterface::class);
    $storage->method('loadByProperties')->willReturn([$this->createMock(PaymentInterface::class)]);
    $this->entityTypeManager->method('getStorage')->with('commerce_payment')->willReturn($storage);
    $this->statusMapper->expects(self::never())->method('apply');

    $result = $this->processor->process(
      $this->gateway,
      $this->gatewayPlugin,
      'same-raw-json',
      'valid-signature',
    );

    self::assertSame(PostbackOutcome::Duplicate, $result->getOutcome());
  }

  /**
   * Tests that a concurrent event waits for the same session lock.
   */
  public function testConcurrentEventWaitsForSessionLock(): void {
    $this->verifier->method('verify')->willReturn(TRUE);
    $this->parser->method('parse')->willReturn($this->createParsedPostback());
    $lock_name = SessionLockName::fromSessionId('session-uuid');
    $this->lock = $this->createMock(LockBackendInterface::class);
    $this->lock->expects(self::exactly(2))->method('acquire')
      ->with($lock_name)
      ->willReturnOnConsecutiveCalls(FALSE, TRUE);
    $this->lock->expects(self::once())->method('wait')->with($lock_name);
    $this->lock->expects(self::once())->method('release')->with($lock_name);
    $this->eventRepository = $this->createMock(
      PostbackEventRepositoryInterface::class,
    );
    $this->eventRepository->method('processOnce')->willReturn(NULL);
    $this->processor = new PostbackProcessor(
      $this->verifier,
      $this->sandboxLegacyVerifier,
      $this->parser,
      $this->entityTypeManager,
      $this->statusMapper,
      $this->eventRepository,
      $this->lock,
      $this->refundManager,
      $this->createMock(NovaPayApiClientInterface::class),
      $this->createMock(PaymentOrderLockInterface::class),
    );

    $storage = $this->createMock(PaymentStorageInterface::class);
    $storage->method('loadByProperties')->willReturn([$this->createMock(PaymentInterface::class)]);
    $this->entityTypeManager->method('getStorage')->with('commerce_payment')->willReturn($storage);

    $result = $this->processor->process(
      $this->gateway,
      $this->gatewayPlugin,
      'concurrent-json',
      'valid-signature',
    );

    self::assertSame(PostbackOutcome::Duplicate, $result->getOutcome());
  }

  /**
   * Tests order-ID fallback ignores foreign sessions and duplicate references.
   */
  public function testExternalOrderReferenceFiltersSessionCandidates(): void {
    $this->verifier->method('verify')->willReturn(TRUE);
    $this->parser->method('parse')->willReturn(new ParsedPostback(
      PostbackVersion::V2,
      NormalizedPostbackEvent::fromValues('session-uuid', 'paid', ['42', 'INV-42']),
    ));
    $order = $this->createMock(OrderInterface::class);
    $order->method('id')->willReturn('42');
    $order_storage = $this->createMock(OrderStorageInterface::class);
    $order_storage->method('loadByProperties')->willReturn([$order, new \stdClass()]);
    $order_storage->method('load')->with('42')->willReturn($order);
    $matched = $this->createMock(PaymentInterface::class);
    $matched->method('id')->willReturn('7');
    $matched->method('getRemoteId')->willReturn('');
    $foreign = $this->createMock(PaymentInterface::class);
    $foreign->method('id')->willReturn('8');
    $foreign->method('getRemoteId')->willReturn('another-session');
    $payment_storage = $this->createMock(PaymentStorageInterface::class);
    $payment_storage->method('loadByProperties')->willReturnCallback(
      static function (array $properties) use ($matched, $foreign): array {
        self::assertSame('novapay_test', $properties['payment_gateway']);
        return isset($properties['remote_id']) ? [] : [$matched, $foreign, new \stdClass()];
      },
    );
    $this->entityTypeManager->method('getStorage')->willReturnMap([
      ['commerce_payment', $payment_storage],
      ['commerce_order', $order_storage],
    ]);
    $this->refundManager->expects(self::once())->method('confirm')->with($matched, self::anything(), self::anything());
    $this->statusMapper->expects(self::once())->method('apply')->with($matched, NovaPayStatus::Paid)->willReturn(TRUE);
    $result = $this->processor->process($this->gateway, $this->gatewayPlugin, 'fallback-json', 'signature');
    self::assertSame(PostbackOutcome::Applied, $result->getOutcome());
    self::assertSame([], $result->getDiagnostics());
    self::assertFalse($result->isDetailedLoggingEnabled());
  }

  /**
   * Tests ambiguous order payments are never mutated by fallback matching.
   */
  public function testAmbiguousExternalOrderReferenceIsNotApplied(): void {
    $this->verifier->method('verify')->willReturn(TRUE);
    $this->parser->method('parse')->willReturn(new ParsedPostback(
      PostbackVersion::V2,
      NormalizedPostbackEvent::fromValues('session-uuid', 'paid', ['INV-42']),
    ));
    $order = $this->createMock(OrderInterface::class);
    $order->method('id')->willReturn('42');
    $order_storage = $this->createMock(OrderStorageInterface::class);
    $order_storage->method('loadByProperties')->willReturn([$order]);
    $payments = [];
    foreach (['7', '8'] as $id) {
      $payment = $this->createMock(PaymentInterface::class);
      $payment->method('id')->willReturn($id);
      $payment->method('getRemoteId')->willReturn('session-uuid');
      $payments[] = $payment;
    }
    $payment_storage = $this->createMock(PaymentStorageInterface::class);
    $payment_storage->method('loadByProperties')->willReturnCallback(
      static fn (array $properties): array => isset($properties['remote_id']) ? [] : $payments,
    );
    $this->entityTypeManager->method('getStorage')->willReturnMap([
      ['commerce_payment', $payment_storage],
      ['commerce_order', $order_storage],
    ]);
    $this->refundManager->expects(self::never())->method('confirm');
    $this->statusMapper->expects(self::never())->method('apply');
    $result = $this->processor->process($this->gateway, $this->gatewayPlugin, 'ambiguous-json', 'signature');
    self::assertSame(PostbackOutcome::UnknownPayment, $result->getOutcome());
  }

  /**
   * Creates a normalized postback event for processor tests.
   */
  private function createParsedPostback(
    NovaPayStatus $status = NovaPayStatus::Holded,
    PostbackVersion $version = PostbackVersion::V2,
  ): ParsedPostback {
    return new ParsedPostback(
      $version,
      NormalizedPostbackEvent::fromValues(
        'session-uuid',
        $status->value,
        [],
      ),
    );
  }

  /**
   * Creates a safe test runtime configuration.
   */
  private function createRuntimeConfiguration(
    NovaPayMode $mode = NovaPayMode::Test,
    bool $detailed_logging = FALSE,
    bool $custom_test = FALSE,
  ): RuntimeConfiguration {
    return new RuntimeConfiguration(
      new RuntimeProfile(
        $mode,
        $mode === NovaPayMode::Live ? 'merchant-live' : NULL,
        TransactionMode::Direct,
        '',
        $detailed_logging,
        RuntimeProfile::DEFAULT_SUCCESS_REDIRECT_TIMEOUT,
        $custom_test,
        $custom_test ? 'custom-test' : NULL,
      ),
      new Credentials(
        $mode,
        $mode === NovaPayMode::Live ? 'merchant-live' : '2',
        'private-key',
        'public-key',
      ),
    );
  }

}
