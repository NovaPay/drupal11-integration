<?php

declare(strict_types=1);

namespace Drupal\commerce_novapay\Api\Dto\Response;

use Drupal\commerce_novapay\Postback\NovaPayStatus;
use Drupal\commerce_novapay\Payment\CaptureOperationEvidence;
use Drupal\commerce_price\Calculator;

/**
 * Contains only the non-sensitive fields needed to reconcile a refund.
 */
final class SessionStatusResponse {

  private const int MAX_IDENTIFIER_BYTES = 255;

  private const string INVALID_RESPONSE = 'Status response is invalid.';

  /**
   * Constructs a projected NovaPay session status.
   *
   * @param string $session_id
   *   NovaPay session identifier.
   * @param \Drupal\commerce_novapay\Postback\NovaPayStatus $status
   *   Current NovaPay session status.
   * @param array<string, string> $refunded_amounts
   *   Cumulative refunded amounts keyed by NovaPay transaction ID.
   * @param string|null $capture_operation_id
   *   Unambiguous confirmed NovaPay transaction identifier.
   */
  private function __construct(
    private readonly string $session_id,
    private readonly NovaPayStatus $status,
    private readonly array $refunded_amounts,
    private readonly ?string $capture_operation_id,
  ) {}

  /**
   * Builds a bounded response without retaining PAN or customer data.
   *
   * @param array<string, mixed> $data
   *   Decoded NovaPay status response.
   */
  public static function fromArray(array $data): self {
    $session_id = self::validateIdentifier($data['id'] ?? NULL);
    $status_value = $data['status'] ?? NULL;
    $status = is_string($status_value)
      ? NovaPayStatus::tryFrom($status_value)
      : NULL;
    if ($status === NULL) {
      throw new \InvalidArgumentException(self::INVALID_RESPONSE);
    }

    $operations = array_key_exists('operations', $data) ? $data['operations'] : [];
    if (!is_array($operations) || !array_is_list($operations)) {
      throw new \InvalidArgumentException(self::INVALID_RESPONSE);
    }
    try {
      $capture_operation_id = CaptureOperationEvidence::fromOperations($operations);
    }
    catch (\InvalidArgumentException) {
      throw new \InvalidArgumentException(self::INVALID_RESPONSE);
    }
    $refunded_amounts = [];
    foreach ($operations as $operation) {
      if (!is_array($operation)) {
        throw new \InvalidArgumentException(self::INVALID_RESPONSE);
      }
      if (
        !array_key_exists('refunded_amount', $operation)
        || $operation['refunded_amount'] === NULL
      ) {
        continue;
      }
      $transaction_id = self::validateIdentifier(
        is_int($operation['transaction_id'] ?? NULL)
          ? (string) $operation['transaction_id']
          : ($operation['transaction_id'] ?? NULL),
      );
      if (array_key_exists($transaction_id, $refunded_amounts)) {
        throw new \InvalidArgumentException(self::INVALID_RESPONSE);
      }
      $refunded_amounts[$transaction_id] = self::validateAmount(
        $operation['refunded_amount'],
      );
    }

    return new self($session_id, $status, $refunded_amounts, $capture_operation_id);
  }

  /**
   * Gets the NovaPay session identifier.
   */
  public function getSessionId(): string {
    return $this->session_id;
  }

  /**
   * Gets the current NovaPay session status.
   */
  public function getStatus(): NovaPayStatus {
    return $this->status;
  }

  /**
   * Gets the cumulative refund amount for a specific payment operation.
   */
  public function getRefundedAmount(string $operation_id): ?string {
    return $this->refunded_amounts[$operation_id] ?? NULL;
  }

  /**
   * Gets the unambiguous confirmed capture transaction identifier.
   */
  public function getCaptureOperationId(): ?string {
    return $this->capture_operation_id;
  }

  /**
   * Validates a bounded NovaPay identifier.
   */
  private static function validateIdentifier(mixed $value): string {
    if (!is_string($value)) {
      throw new \InvalidArgumentException(self::INVALID_RESPONSE);
    }
    $value = trim($value);
    if (
      $value === ''
      || strlen($value) > self::MAX_IDENTIFIER_BYTES
      || preg_match('/[\x00-\x1F\x7F]/', $value) === 1
    ) {
      throw new \InvalidArgumentException(self::INVALID_RESPONSE);
    }
    return $value;
  }

  /**
   * Validates and normalizes an exact non-negative decimal amount.
   */
  private static function validateAmount(mixed $value): string {
    if (is_int($value)) {
      $value = (string) $value;
    }
    elseif (is_float($value) && is_finite($value)) {
      $encoded = json_encode($value, JSON_PRESERVE_ZERO_FRACTION);
      $value = is_string($encoded) ? $encoded : '';
    }
    if (
      !is_string($value)
      || preg_match('/^(?:0|[1-9][0-9]*)(?:\.[0-9]{1,6})?$/D', $value) !== 1
    ) {
      throw new \InvalidArgumentException(self::INVALID_RESPONSE);
    }
    return Calculator::trim($value);
  }

}
