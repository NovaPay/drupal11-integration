<?php

declare(strict_types=1);

namespace Drupal\Tests\commerce_novapay\Unit\Runtime;

use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\commerce_novapay\Credential\NovaPayMode;
use Drupal\commerce_novapay\Exception\InvalidRuntimeProfileException;
use Drupal\commerce_novapay\Runtime\CredentialChangeGuard;
use Drupal\commerce_novapay\Runtime\RuntimeProfile;
use Drupal\commerce_novapay\Runtime\TransactionMode;
use Drupal\commerce_payment\Entity\PaymentGatewayInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Verifies credential changes cannot adopt existing payment sessions.
 */
#[CoversClass(CredentialChangeGuard::class)]
final class CredentialChangeGuardTest extends TestCase {

  /**
   * Tests changes with saved remote payments and harmless profile updates.
   */
  #[DataProvider('changeProvider')]
  public function testCredentialChange(bool $custom, bool $upload, bool $existing, bool $rejected): void {
    $manager = $this->createMock(EntityTypeManagerInterface::class);
    $guard = new CredentialChangeGuard($manager);
    $previous = new RuntimeProfile(NovaPayMode::Test, NULL, TransactionMode::Direct, '', FALSE);
    $next = new RuntimeProfile(NovaPayMode::Test, NULL, TransactionMode::Hold, '', TRUE, 10, $custom, $custom ? '123' : NULL);
    if (!$custom && !$upload) {
      $manager->expects(self::never())->method('getStorage');
    }
    else {
      $gateway = $this->createMock(PaymentGatewayInterface::class);
      $gateway->method('id')->willReturn('test_gateway');
      $gateways = $this->createMock(EntityStorageInterface::class);
      $gateways->expects(self::once())->method('loadByProperties')->with(['uuid' => 'gateway-uuid'])->willReturn([$gateway]);
      $payments = $this->createMock(EntityStorageInterface::class);
      $query = $this->createMock(QueryInterface::class);
      $query->expects(self::once())->method('accessCheck')->with(FALSE)->willReturnSelf();
      $conditions = [];
      $query->method('condition')->willReturnCallback(function ($field, $value, $operator = NULL) use (&$conditions, $query) {
        $conditions[] = [$field, $value, $operator];
        return $query;
      });
      $query->expects(self::once())->method('range')->with(0, 1)->willReturnSelf();
      $query->method('execute')->willReturn($existing ? [99] : []);
      $payments->method('getQuery')->willReturn($query);
      $manager->method('getStorage')->willReturnMap([
        ['commerce_payment_gateway', $gateways],
        ['commerce_payment', $payments],
      ]);
      if ($rejected) {
        $this->expectException(InvalidRuntimeProfileException::class);
        $this->expectExceptionMessage('separate gateway');
      }
    }
    $guard->assertChangeAllowed('gateway-uuid', $previous, $next, $upload);
    if (isset($conditions)) {
      self::assertSame([
        ['payment_gateway', 'test_gateway', NULL],
        ['remote_id', '', '<>'],
      ], $conditions);
    }
  }

  /**
   * Supplies active source/key changes and harmless noncredential settings.
   *
   * @return array<string, array{bool, bool, bool, bool}>
   *   Source, upload, existing session, and expected rejection.
   */
  public static function changeProvider(): array {
    return [
      'new source with old sessions' => [TRUE, FALSE, TRUE, TRUE],
      'key rotation with old sessions' => [FALSE, TRUE, TRUE, TRUE],
      'new source without sessions' => [TRUE, FALSE, FALSE, FALSE],
      'keys without sessions' => [FALSE, TRUE, FALSE, FALSE],
      'harmless settings' => [FALSE, FALSE, TRUE, FALSE],
    ];
  }

}
