<?php

declare(strict_types=1);

namespace Drupal\commerce_novapay\Payment;

use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\commerce_novapay\Exception\ApiProcessingException;
use Drupal\commerce_novapay\Exception\NovaPayApiException;

/**
 * Translates the approved refund catalog without displaying API response text.
 */
trait RefundFailureMessageTrait {

  use StringTranslationTrait;

  /**
   * Gets a known refund/hold-void message, or NULL for an unknown failure.
   */
  private function getRefundFailureMessage(\Throwable $exception): ?string {
    // HTTP 500 has no error code in the supplied refund catalog.
    if ($exception instanceof NovaPayApiException && $exception->getHttpStatus() === 500) {
      return (string) $this->t('The refund could not be processed due to an internal error. Try again later. If the error persists, contact NovaPay support and provide the order details.');
    }
    if (!$exception instanceof ApiProcessingException) {
      return NULL;
    }

    return match ($exception->getApiCode()) {
      'NotFoundError' => (string) $this->t('Refund data could not be found. Check the request identifiers. If they are correct, contact NovaPay support and provide the order details.'),
      'SessionNotFoundError' => (string) $this->t('The payment session is unavailable for a refund. Contact NovaPay support and provide the order details.'),
      'SessionAlreadyRefundedError' => (string) $this->t('The refund cannot be performed. A refund for this session has already been registered.'),
      'OperationAlreadyRefundedError' => (string) $this->t('A refund has already been registered for this operation. Check its current status before submitting another request.'),
      'RefundError' => (string) $this->t('The refund cannot be performed with the supplied parameters. Contact NovaPay support for clarification.'),
      'RefundTryLaterError' => (string) $this->t('The operation is temporarily unavailable for a refund. Try again later.'),
      'UnsupportedProductError' => (string) $this->t('This refund method is unavailable for the specified operation. Contact NovaPay support to clarify the available refund method.'),
      'RefundPeriodExpiredError' => (string) $this->t('The allowed refund period has expired. Contact NovaPay support to clarify the next steps.'),
      'WrongAmountError' => (string) $this->t('The refund amount is invalid. Check the amount available for refunding this operation.'),
      'ContragentMissingTreatyIdError' => (string) $this->t('The refund is unavailable due to the recipient settings. Contact NovaPay support and provide the order details.'),
      'UnknownPaymentError' => (string) $this->t('The refund could not be processed. Try again later. If the error persists, contact NovaPay support and provide the order details.'),
      'WrongNovapayCardSessionStatusError' => (string) $this->t('The refund cannot be performed in the current operation status. Check the payment status and try again later.'),
      'UnknownNovaclientsError' => (string) $this->t('The refund could not be processed due to a temporary technical error. Try again later or contact NovaPay support.'),
      'UnknownSignerError' => (string) $this->t('The refund could not be finalized. Try again later. If the error persists, contact NovaPay support.'),
      '1c service request failed' => (string) $this->t('The refund could not be finalized due to a temporary technical error. Try again later.'),
      '1c service bad response' => (string) $this->t('The refund could not be finalized. Try again later or contact NovaPay support.'),
      'DataIntegrityError' => (string) $this->t('The refund could not be processed due to a data error. Contact NovaPay support and provide the order details.'),
      'QueryError' => (string) $this->t('The refund could not be processed. Check the request parameters. If they are correct, contact NovaPay support and provide the order details.'),
      'MerchantAccessDenied' => (string) $this->t('The request could not be authorized. Check the merchant identifier and credentials.'),
      'InvalidSignError' => (string) $this->t('The request signature could not be verified. Check the signing algorithm and key.'),
      'IpAddressNotRegisteredError' => (string) $this->t('The request was sent from an unauthorized IP address. Use a registered address or contact NovaPay to update the settings.'),
      default => NULL,
    };
  }

}
