<?php

declare(strict_types=1);

namespace Drupal\commerce_novapay\Runtime;

/**
 * Protects existing sessions from changes to their gateway credentials.
 */
interface CredentialChangeGuardInterface {

  /**
   * Rejects credential changes while a gateway has existing remote payments.
   */
  public function assertChangeAllowed(string $gateway_uuid, ?RuntimeProfile $previous, RuntimeProfile $next, bool $has_upload): void;

}
