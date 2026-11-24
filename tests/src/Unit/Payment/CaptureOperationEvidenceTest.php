<?php

declare(strict_types=1);

namespace Drupal\Tests\commerce_novapay\Unit\Payment;

use Drupal\commerce_novapay\Api\Dto\Response\SessionStatusResponse;
use Drupal\commerce_novapay\Exception\InvalidPostbackException;
use Drupal\commerce_novapay\Payment\CaptureOperationEvidence;
use Drupal\commerce_novapay\Postback\Parser\V2PostbackParser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Tests bounded unambiguous projection shared by callbacks and status recovery.
 */
#[CoversClass(CaptureOperationEvidence::class)]
#[CoversClass(SessionStatusResponse::class)]
#[CoversClass(V2PostbackParser::class)]
#[Group('commerce_novapay')]
final class CaptureOperationEvidenceTest extends TestCase {

  /**
   * Tests absence, duplicates, ambiguity and confirmed status filtering.
   */
  public function testUnambiguousEvidence(): void {
    self::assertNull(CaptureOperationEvidence::fromOperations([]));
    self::assertNull(CaptureOperationEvidence::fromOperations([['external_id' => 'order-1']]));
    self::assertSame('42', CaptureOperationEvidence::fromOperations([
      ['transaction_id' => 42, 'status' => 'paid'],
      ['transaction_id' => '42', 'status' => 'hold_confirmed'],
      ['transaction_id' => 'unpaid-1', 'status' => 'failed'],
    ]));
    self::assertNull(CaptureOperationEvidence::fromOperations([
      ['transaction_id' => 'capture-1'],
      ['transaction_id' => 'capture-2'],
    ]));
  }

  /**
   * Tests the same capture ID is available from callback and status response.
   */
  public function testProjectsCallbackAndStatusEvidence(): void {
    $payload = [
      'id' => 'session-1',
      'status' => 'hold_confirmed',
      'payments' => [['external_id' => 'order-1', 'amount' => '10.00']],
      'operations' => [['transaction_id' => 42, 'status' => 'paid', 'pan' => 'discarded']],
    ];
    $event = (new V2PostbackParser())->parse($payload);
    $response = SessionStatusResponse::fromArray($payload);
    self::assertSame('42', $event->getCaptureOperationId());
    self::assertSame('42', $response->getCaptureOperationId());
    unset($payload['operations']);
    self::assertNull((new V2PostbackParser())->parse($payload)->getCaptureOperationId());
    self::assertNull(SessionStatusResponse::fromArray($payload)->getCaptureOperationId());
  }

  /**
   * Tests invalid evidence fails closed at each trust boundary.
   */
  #[DataProvider('invalidOperationsProvider')]
  public function testRejectsMalformedEvidence(mixed $operations): void {
    $payload = [
      'id' => 'session-1',
      'status' => 'paid',
      'payments' => [['external_id' => 'order-1', 'amount' => 10]],
      'operations' => $operations,
    ];
    try {
      SessionStatusResponse::fromArray($payload);
      self::fail('Status response must reject malformed operation evidence.');
    }
    catch (\InvalidArgumentException $exception) {
      self::assertSame('Status response is invalid.', $exception->getMessage());
    }
    $this->expectException(InvalidPostbackException::class);
    (new V2PostbackParser())->parse($payload);
  }

  /**
   * Provides malformed lists and transaction identifiers.
   *
   * @return iterable<string, array{mixed}>
   *   Invalid operation evidence.
   */
  public static function invalidOperationsProvider(): iterable {
    yield 'null' => [NULL];
    yield 'scalar' => ['invalid'];
    yield 'map' => [['named' => []]];
    yield 'scalar entry' => [[1]];
    yield 'unbounded list' => [array_fill(0, 101, [])];
    foreach ([NULL, TRUE, 1.5, 0, -1, '', ' ', "id\0x", str_repeat('x', 256)] as $index => $id) {
      yield 'id-' . $index => [[['transaction_id' => $id]]];
    }
  }

}
