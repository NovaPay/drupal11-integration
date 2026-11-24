<?php

declare(strict_types=1);

namespace Drupal\Tests\commerce_novapay\Unit\Api\Dto;

use Drupal\commerce_novapay\Api\Dto\Response\SessionStatusResponse;
use Drupal\commerce_novapay\Postback\NovaPayStatus;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Tests bounded status projection and exact refund evidence validation.
 */
#[CoversClass(SessionStatusResponse::class)]
#[Group('commerce_novapay')]
final class SessionStatusResponseTest extends TestCase {

  /**
   * Tests optional operations, normalized identifiers and absent evidence.
   */
  public function testStatusWithoutRefundEvidence(): void {
    $response = SessionStatusResponse::fromArray([
      'id' => ' session-1 ',
      'status' => 'paid',
    ]);
    self::assertSame('session-1', $response->getSessionId());
    self::assertSame(NovaPayStatus::Paid, $response->getStatus());
    self::assertNull($response->getRefundedAmount('operation-1'));
  }

  /**
   * Tests refund evidence projection without retaining unrelated payload data.
   */
  #[DataProvider('amountProvider')]
  public function testProjectsRefundAmount(mixed $amount, string $expected): void {
    $response = SessionStatusResponse::fromArray([
      'id' => 'session-1',
      'status' => 'paid',
      'operations' => [
        ['transaction_id' => 'ignored'],
        ['transaction_id' => 'ignored-null', 'refunded_amount' => NULL],
        ['transaction_id' => ' operation-1 ', 'refunded_amount' => $amount],
      ],
    ]);
    self::assertSame($expected, $response->getRefundedAmount('operation-1'));
    self::assertNull($response->getRefundedAmount('ignored'));
    self::assertNull($response->getRefundedAmount('ignored-null'));
  }

  /**
   * Provides the supported exact decimal representations.
   *
   * @return iterable<string, array{mixed, string}>
   *   Incoming amount and normalized decimal string.
   */
  public static function amountProvider(): iterable {
    yield 'integer' => [12, '12'];
    yield 'decimal string' => ['12.340000', '12.34'];
    yield 'JSON decimal' => [12.5, '12.5'];
    yield 'zero' => [0, '0'];
    yield 'large exact string' => ['9007199254740993.123456', '9007199254740993.123456'];
  }

  /**
   * Tests fail-closed rejection with a fixed non-sensitive error message.
   *
   * @param array<string, mixed> $data
   *   Invalid incoming response.
   */
  #[DataProvider('invalidResponseProvider')]
  public function testRejectsInvalidResponse(array $data): void {
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('Status response is invalid.');
    SessionStatusResponse::fromArray($data);
  }

  /**
   * Provides malformed identifiers, status, operations and monetary evidence.
   *
   * @return iterable<string, array{array<string, mixed>}>
   *   Invalid incoming responses.
   */
  public static function invalidResponseProvider(): iterable {
    $valid = ['id' => 'session-1', 'status' => 'paid'];
    foreach ([NULL, 1, '', ' ', "id\0x", str_repeat('a', 256)] as $index => $id) {
      yield 'id-' . $index => [array_replace($valid, ['id' => $id])];
    }
    foreach ([NULL, 1, 'unknown'] as $index => $status) {
      yield 'status-' . $index => [array_replace($valid, ['status' => $status])];
    }
    yield 'duplicate transaction refunds' => [$valid + ['operations' => [
      ['transaction_id' => 'operation-1', 'refunded_amount' => '5'],
      ['transaction_id' => 'operation-1', 'refunded_amount' => '15'],
    ]]];
    yield 'operations scalar' => [$valid + ['operations' => 'invalid']];
    yield 'operations map' => [$valid + ['operations' => ['named' => []]]];
    yield 'operation scalar' => [$valid + ['operations' => [1]]];
    yield 'missing transaction id' => [$valid + ['operations' => [['refunded_amount' => '1']]]];
    foreach ([-1, '-1', '01', '1e2', TRUE, [], INF, NAN, '0.0000001'] as $index => $amount) {
      yield 'amount-' . $index => [$valid + ['operations' => [[
        'transaction_id' => 'operation-1',
        'refunded_amount' => $amount,
      ]]]];
    }
  }

}
