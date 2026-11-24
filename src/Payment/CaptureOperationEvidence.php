<?php

declare(strict_types=1);

namespace Drupal\commerce_novapay\Payment;

/**
 * Projects one unambiguous confirmed operation without retaining payload data.
 */
final class CaptureOperationEvidence {

  private const int MAX_OPERATIONS = 100;

  private const int MAX_IDENTIFIER_BYTES = 255;

  /**
   * Gets one distinct transaction ID, or NULL for absent/ambiguous data.
   */
  public static function fromOperations(mixed $operations): ?string {
    if (
      !is_array($operations)
      || !array_is_list($operations)
      || count($operations) > self::MAX_OPERATIONS
    ) {
      throw new \InvalidArgumentException('Operation evidence is invalid.');
    }
    $identifiers = [];
    foreach ($operations as $operation) {
      if (!is_array($operation)) {
        throw new \InvalidArgumentException('Operation evidence is invalid.');
      }
      if (
        array_key_exists('status', $operation)
        && !in_array($operation['status'], ['paid', 'hold_confirmed'], TRUE)
      ) {
        continue;
      }
      if (!array_key_exists('transaction_id', $operation)) {
        continue;
      }
      $id = $operation['transaction_id'];
      if (is_int($id)) {
        if ($id <= 0) {
          throw new \InvalidArgumentException('Operation evidence is invalid.');
        }
        $id = (string) $id;
      }
      if (
        !is_string($id)
        || strlen($id) > self::MAX_IDENTIFIER_BYTES
        || trim($id) === ''
        || preg_match('/[\x00-\x1F\x7F]/', $id) === 1
      ) {
        throw new \InvalidArgumentException('Operation evidence is invalid.');
      }
      $id = trim($id);
      $identifiers[$id] = $id;
    }
    return count($identifiers) === 1 ? array_values($identifiers)[0] : NULL;
  }

}
