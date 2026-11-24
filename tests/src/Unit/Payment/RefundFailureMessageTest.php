<?php

declare(strict_types=1);

namespace Drupal\Tests\commerce_novapay\Unit\Payment;

use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\commerce_novapay\Exception\ApiFatalException;
use Drupal\commerce_novapay\Exception\ApiProcessingException;
use Drupal\commerce_novapay\Exception\ApiTransportException;
use Drupal\commerce_novapay\Payment\RefundFailureMessageTrait;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the approved refund catalog and safe unknown-code fallback.
 */
#[CoversTrait(RefundFailureMessageTrait::class)]
#[Group('commerce_novapay')]
final class RefundFailureMessageTest extends UnitTestCase {

  /**
   * Tests all catalog codes and the code-less HTTP 500 message.
   */
  #[DataProvider('catalogProvider')]
  public function testCatalog(\Throwable $exception, ?string $expected): void {
    $translator = new class {
      use RefundFailureMessageTrait;

      /**
       * Exposes the shared refund message for the test.
       */
      public function message(\Throwable $exception): ?string {
        return $this->getRefundFailureMessage($exception);
      }

    };
    $translation = $this->getStringTranslationStub();
    self::assertInstanceOf(TranslationInterface::class, $translation);
    $translator->setStringTranslation($translation);
    self::assertSame($expected, $translator->message($exception));
  }

  /**
   * Provides the catalog plus failures that must not expose arbitrary text.
   *
   * @return iterable<string, array{\Throwable, ?string}>
   *   Safe exception and expected English translation source.
   */
  public static function catalogProvider(): iterable {
    yield 'NotFoundError' => [
      new ApiProcessingException(400, 'NotFoundError'),
      'Refund data could not be found. Check the request identifiers. If they are correct, contact NovaPay support and provide the order details.',
    ];
    yield 'SessionNotFoundError' => [
      new ApiProcessingException(400, 'SessionNotFoundError'),
      'The payment session is unavailable for a refund. Contact NovaPay support and provide the order details.',
    ];
    yield 'SessionAlreadyRefundedError' => [
      new ApiProcessingException(400, 'SessionAlreadyRefundedError'),
      'The refund cannot be performed. A refund for this session has already been registered.',
    ];
    yield 'OperationAlreadyRefundedError' => [
      new ApiProcessingException(400, 'OperationAlreadyRefundedError'),
      'A refund has already been registered for this operation. Check its current status before submitting another request.',
    ];
    yield 'RefundError' => [
      new ApiProcessingException(400, 'RefundError'),
      'The refund cannot be performed with the supplied parameters. Contact NovaPay support for clarification.',
    ];
    yield 'RefundTryLaterError' => [
      new ApiProcessingException(400, 'RefundTryLaterError'),
      'The operation is temporarily unavailable for a refund. Try again later.',
    ];
    yield 'UnsupportedProductError' => [
      new ApiProcessingException(400, 'UnsupportedProductError'),
      'This refund method is unavailable for the specified operation. Contact NovaPay support to clarify the available refund method.',
    ];
    yield 'RefundPeriodExpiredError' => [
      new ApiProcessingException(400, 'RefundPeriodExpiredError'),
      'The allowed refund period has expired. Contact NovaPay support to clarify the next steps.',
    ];
    yield 'WrongAmountError' => [
      new ApiProcessingException(400, 'WrongAmountError'),
      'The refund amount is invalid. Check the amount available for refunding this operation.',
    ];
    yield 'ContragentMissingTreatyIdError' => [
      new ApiProcessingException(400, 'ContragentMissingTreatyIdError'),
      'The refund is unavailable due to the recipient settings. Contact NovaPay support and provide the order details.',
    ];
    yield 'UnknownPaymentError' => [
      new ApiProcessingException(400, 'UnknownPaymentError'),
      'The refund could not be processed. Try again later. If the error persists, contact NovaPay support and provide the order details.',
    ];
    yield 'WrongNovapayCardSessionStatusError' => [
      new ApiProcessingException(400, 'WrongNovapayCardSessionStatusError'),
      'The refund cannot be performed in the current operation status. Check the payment status and try again later.',
    ];
    yield 'UnknownNovaclientsError' => [
      new ApiProcessingException(400, 'UnknownNovaclientsError'),
      'The refund could not be processed due to a temporary technical error. Try again later or contact NovaPay support.',
    ];
    yield 'UnknownSignerError' => [
      new ApiProcessingException(400, 'UnknownSignerError'),
      'The refund could not be finalized. Try again later. If the error persists, contact NovaPay support.',
    ];
    yield '1c service request failed' => [
      new ApiProcessingException(400, '1c service request failed'),
      'The refund could not be finalized due to a temporary technical error. Try again later.',
    ];
    yield '1c service bad response' => [
      new ApiProcessingException(400, '1c service bad response'),
      'The refund could not be finalized. Try again later or contact NovaPay support.',
    ];
    yield 'DataIntegrityError' => [
      new ApiProcessingException(400, 'DataIntegrityError'),
      'The refund could not be processed due to a data error. Contact NovaPay support and provide the order details.',
    ];
    yield 'QueryError' => [
      new ApiProcessingException(400, 'QueryError'),
      'The refund could not be processed. Check the request parameters. If they are correct, contact NovaPay support and provide the order details.',
    ];
    yield 'MerchantAccessDenied' => [
      new ApiProcessingException(403, 'MerchantAccessDenied'),
      'The request could not be authorized. Check the merchant identifier and credentials.',
    ];
    yield 'InvalidSignError' => [
      new ApiProcessingException(400, 'InvalidSignError'),
      'The request signature could not be verified. Check the signing algorithm and key.',
    ];
    yield 'IpAddressNotRegisteredError' => [
      new ApiProcessingException(400, 'IpAddressNotRegisteredError'),
      'The request was sent from an unauthorized IP address. Use a registered address or contact NovaPay to update the settings.',
    ];
    yield 'Відсутній' => [
      new ApiFatalException(500),
      'The refund could not be processed due to an internal error. Try again later. If the error persists, contact NovaPay support and provide the order details.',
    ];
    yield 'unknown code' => [new ApiProcessingException(400, '<script>secret</script>'), NULL];
    yield 'missing code' => [new ApiProcessingException(400, NULL), NULL];
    yield 'transport' => [ApiTransportException::requestFailed(), NULL];
    yield 'arbitrary exception' => [new \RuntimeException('secret'), NULL];
  }

}
