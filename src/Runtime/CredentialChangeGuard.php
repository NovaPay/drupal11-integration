<?php

declare(strict_types=1);

namespace Drupal\commerce_novapay\Runtime;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\commerce_novapay\Credential\NovaPayMode;
use Drupal\commerce_novapay\Exception\InvalidRuntimeProfileException;

/**
 * Requires a separate gateway when credentials already own remote sessions.
 */
final class CredentialChangeGuard implements CredentialChangeGuardInterface {

  public function __construct(
    private readonly EntityTypeManagerInterface $entity_type_manager,
  ) {}

  /**
   * Names the lock shared by profile saves and checkout session creation.
   */
  public static function getOperationLockName(string $gateway_uuid): string {
    return 'commerce_novapay.credential_operation.' . $gateway_uuid;
  }

  /**
   * {@inheritdoc}
   */
  public function assertChangeAllowed(string $gateway_uuid, ?RuntimeProfile $previous, RuntimeProfile $next, bool $has_upload): void {
    if (!$has_upload && $previous !== NULL && self::identity($previous) === self::identity($next)) {
      return;
    }
    $gateways = $this->entity_type_manager->getStorage('commerce_payment_gateway')
      ->loadByProperties(['uuid' => $gateway_uuid]);
    foreach ($gateways as $gateway) {
      $ids = $this->entity_type_manager->getStorage('commerce_payment')
        ->getQuery()
        ->accessCheck(FALSE)
        ->condition('payment_gateway', $gateway->id())
        ->condition('remote_id', '', '<>')
        ->range(0, 1)
        ->execute();
      if ($ids !== []) {
        throw new InvalidRuntimeProfileException('This gateway already has NovaPay sessions. Keep its mode and credentials unchanged and create a separate gateway for different credentials.');
      }
    }
  }

  /**
   * Describes the active credential selection without reading or storing keys.
   *
   * @return array<string|null|bool>
   *   The active environment, source, and merchant identity.
   */
  private static function identity(RuntimeProfile $profile): array {
    if ($profile->getMode() === NovaPayMode::Live) {
      return ['live', $profile->getLiveMerchantId()];
    }
    return [
      'test',
      $profile->usesCustomTestCredentials(),
      $profile->usesCustomTestCredentials() ? $profile->getTestMerchantId() : NULL,
    ];
  }

}
