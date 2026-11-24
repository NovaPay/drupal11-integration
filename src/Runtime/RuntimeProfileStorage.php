<?php

declare(strict_types=1);

namespace Drupal\commerce_novapay\Runtime;

use Drupal\Core\Lock\LockBackendInterface;
use Drupal\commerce_novapay\Credential\CredentialResolver;
use Drupal\commerce_novapay\Credential\NovaPayMode;
use Drupal\commerce_novapay\Credential\RsaKeyValidatorInterface;
use Drupal\commerce_novapay\Exception\InvalidRuntimeProfileException;

/**
 * Stores runtime settings and keys in the Drupal private filesystem.
 */
final class RuntimeProfileStorage implements RuntimeProfileStorageInterface {

  private const int MAX_PROFILE_BYTES = 65536;

  private const int MAX_KEY_BYTES = 65536;

  /**
   * Constructs an environment-local runtime profile storage.
   */
  public function __construct(
    private readonly RsaKeyValidatorInterface $key_validator,
    private readonly LockBackendInterface $lock,
    private readonly string $private_base_uri = 'private://commerce_novapay',
    private readonly ?CredentialChangeGuardInterface $credential_change_guard = NULL,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function load(string $gateway_uuid): ?RuntimeProfile {
    $this->assertGatewayUuid($gateway_uuid);
    $uri = $this->getDirectory($gateway_uuid) . '/settings.json';
    if (!@is_file($uri)) {
      return NULL;
    }

    $lock_name = CredentialResolver::getLockName($gateway_uuid);
    if (!$this->acquireLock($lock_name)) {
      throw InvalidRuntimeProfileException::lockUnavailable();
    }

    try {
      $contents = $this->readFile($uri, self::MAX_PROFILE_BYTES);
      if ($contents === NULL) {
        throw InvalidRuntimeProfileException::profileUnavailable();
      }

      try {
        $values = json_decode(
          $contents,
          TRUE,
          16,
          JSON_THROW_ON_ERROR,
        );
      }
      catch (\JsonException) {
        throw InvalidRuntimeProfileException::invalidProfile();
      }

      if (!is_array($values)) {
        throw InvalidRuntimeProfileException::invalidProfile();
      }

      return RuntimeProfile::fromArray($values);
    }
    finally {
      $this->lock->release($lock_name);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function assertWritable(string $gateway_uuid): void {
    $this->assertGatewayUuid($gateway_uuid);
    $directory = $this->getDirectory($gateway_uuid);
    $scheme = parse_url($directory, PHP_URL_SCHEME);
    if (
      is_string($scheme)
      && !in_array($scheme, stream_get_wrappers(), TRUE)
    ) {
      throw InvalidRuntimeProfileException::privateStorageUnavailable();
    }

    if (
      !@is_dir($directory)
      && !@mkdir($directory, 0700, TRUE)
      && !@is_dir($directory)
    ) {
      throw InvalidRuntimeProfileException::privateStorageUnavailable();
    }

    if (!@chmod($directory, 0700) || !@is_writable($directory)) {
      throw InvalidRuntimeProfileException::privateStorageUnavailable();
    }
  }

  /**
   * {@inheritdoc}
   */
  public function hasValidLiveKeys(string $gateway_uuid): bool {
    return $this->hasValidKeys($gateway_uuid, FALSE);
  }

  /**
   * {@inheritdoc}
   */
  public function hasValidTestKeys(string $gateway_uuid): bool {
    return $this->hasValidKeys($gateway_uuid, TRUE);
  }

  /**
   * Validates a complete environment-specific key set under the shared lock.
   */
  private function hasValidKeys(string $gateway_uuid, bool $test): bool {
    $this->assertGatewayUuid($gateway_uuid);
    $lock_name = CredentialResolver::getLockName($gateway_uuid);
    if (!$this->acquireLock($lock_name)) {
      throw InvalidRuntimeProfileException::lockUnavailable();
    }
    try {
      return $this->hasValidKeysWithoutLock($gateway_uuid, $test);
    }
    finally {
      $this->lock->release($lock_name);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function save(
    string $gateway_uuid,
    RuntimeProfile $profile,
    #[\SensitiveParameter]
    ?string $private_key_pem = NULL,
    #[\SensitiveParameter]
    ?string $public_key_pem = NULL,
  ): void {
    $this->assertGatewayUuid($gateway_uuid);
    $operation_lock_name = CredentialChangeGuard::getOperationLockName($gateway_uuid);
    if (!$this->acquireLock($operation_lock_name, 120.0)) {
      throw InvalidRuntimeProfileException::lockUnavailable();
    }
    $lock_name = CredentialResolver::getLockName($gateway_uuid);
    if (!$this->acquireLock($lock_name)) {
      $this->lock->release($operation_lock_name);
      throw InvalidRuntimeProfileException::lockUnavailable();
    }

    try {
      $this->assertWritable($gateway_uuid);
      $has_private_upload = $private_key_pem !== NULL;
      $has_public_upload = $public_key_pem !== NULL;
      if ($has_private_upload !== $has_public_upload) {
        throw InvalidRuntimeProfileException::incompleteKeyUpload();
      }

      if ($this->credential_change_guard !== NULL) {
        $previous = NULL;
        $profile_uri = $this->getDirectory($gateway_uuid) . '/settings.json';
        if (is_file($profile_uri)) {
          $contents = $this->readFile($profile_uri, self::MAX_PROFILE_BYTES);
          try {
            $values = json_decode($contents ?? '', TRUE, 16, JSON_THROW_ON_ERROR);
          }
          catch (\JsonException) {
            throw InvalidRuntimeProfileException::invalidProfile();
          }
          if (!is_array($values)) {
            throw InvalidRuntimeProfileException::invalidProfile();
          }
          $previous = RuntimeProfile::fromArray($values);
        }
        $this->credential_change_guard->assertChangeAllowed(
          $gateway_uuid,
          $previous,
          $profile,
          $has_private_upload || $has_public_upload,
        );
      }

      $files = [
        'settings.json' => json_encode(
          $profile->toArray(),
          JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        ) . "\n",
      ];

      $test = $profile->getMode() === NovaPayMode::Test;
      $prefix = $test ? 'test-' : '';
      if (!$test || $profile->usesCustomTestCredentials()) {
        if ($has_private_upload && $has_public_upload) {
          $this->key_validator->validatePrivateKey($private_key_pem);
          $this->key_validator->validatePublicKey($public_key_pem);
          $files = [
            $prefix . 'private.pem' => $private_key_pem,
            $prefix . 'public.pem' => $public_key_pem,
          ] + $files;
        }
        elseif (!$this->hasValidKeysWithoutLock($gateway_uuid, $test)) {
          throw InvalidRuntimeProfileException::incompleteKeyUpload();
        }
      }

      $this->activateFiles($gateway_uuid, $files);
    }
    finally {
      $this->lock->release($lock_name);
      $this->lock->release($operation_lock_name);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function delete(string $gateway_uuid): void {
    $this->assertGatewayUuid($gateway_uuid);
    $lock_name = CredentialResolver::getLockName($gateway_uuid);
    if (!$this->acquireLock($lock_name)) {
      throw InvalidRuntimeProfileException::lockUnavailable();
    }

    try {
      $directory = $this->getDirectory($gateway_uuid);
      if (!is_dir($directory)) {
        return;
      }

      $entries = @scandir($directory);
      if (!is_array($entries)) {
        throw InvalidRuntimeProfileException::writeFailed();
      }

      foreach ($entries as $entry) {
        if (
          in_array(
            $entry,
            ['settings.json', 'private.pem', 'public.pem', 'test-private.pem', 'test-public.pem'],
            TRUE,
          )
          || preg_match(
            '/^\\.(settings\\.json|(?:test-)?private\\.pem|(?:test-)?public\\.pem)\\.(tmp|bak)-[0-9a-f]{32}$/D',
            $entry,
          ) === 1
        ) {
          $uri = $directory . '/' . $entry;
          if (!@unlink($uri) && is_file($uri)) {
            throw InvalidRuntimeProfileException::writeFailed();
          }
        }
      }

      if (!@rmdir($directory)) {
        throw InvalidRuntimeProfileException::writeFailed();
      }
    }
    finally {
      $this->lock->release($lock_name);
    }
  }

  /**
   * Checks stored keys while the caller holds the profile lock.
   *
   * @param string $gateway_uuid
   *   The Commerce payment gateway UUID.
   * @param bool $test
   *   Whether to validate custom sandbox files instead of live files.
   */
  private function hasValidKeysWithoutLock(string $gateway_uuid, bool $test): bool {
    $directory = $this->getDirectory($gateway_uuid);
    $prefix = $test ? 'test-' : '';
    $private_key = $this->readFile(
      $directory . '/' . $prefix . 'private.pem',
      self::MAX_KEY_BYTES,
    );
    $public_key = $this->readFile(
      $directory . '/' . $prefix . 'public.pem',
      self::MAX_KEY_BYTES,
    );
    if ($private_key === NULL || $public_key === NULL) {
      return FALSE;
    }

    try {
      $this->key_validator->validatePrivateKey($private_key);
      $this->key_validator->validatePublicKey($public_key);
    }
    catch (\RuntimeException) {
      return FALSE;
    }

    return TRUE;
  }

  /**
   * Activates a staged set of files with rollback under the profile lock.
   *
   * @param string $gateway_uuid
   *   The Commerce payment gateway UUID.
   * @param array<string, string> $files
   *   File contents indexed by stable target filename.
   */
  private function activateFiles(string $gateway_uuid, array $files): void {
    $directory = $this->getDirectory($gateway_uuid);
    $token = bin2hex(random_bytes(16));
    $temporary_files = [];
    $backups = [];
    $activated = [];

    try {
      foreach ($files as $filename => $contents) {
        $temporary_files[$filename] = $this->stageFile(
          $directory,
          $filename,
          $contents,
          $token,
        );
      }

      foreach ($temporary_files as $filename => $temporary_uri) {
        $target_uri = $directory . '/' . $filename;
        $backup_uri = $directory . '/.' . $filename . '.bak-' . $token;
        if ($this->backupFile($target_uri, $backup_uri)) {
          $backups[$filename] = $backup_uri;
        }

        if (!@rename($temporary_uri, $target_uri)) {
          throw InvalidRuntimeProfileException::writeFailed();
        }
        unset($temporary_files[$filename]);
        $activated[$filename] = $target_uri;

        if (!@chmod($target_uri, 0600)) {
          throw InvalidRuntimeProfileException::writeFailed();
        }
      }

    }
    catch (\Throwable $exception) {
      $this->restoreFiles($directory, $activated, $backups, $temporary_files);

      if ($exception instanceof InvalidRuntimeProfileException) {
        throw $exception;
      }
      throw InvalidRuntimeProfileException::writeFailed();
    }

    foreach ($backups as $backup_uri) {
      if (!@unlink($backup_uri) && is_file($backup_uri)) {
        throw InvalidRuntimeProfileException::writeFailed();
      }
    }
  }

  /**
   * Moves an existing target to its rollback backup before activation.
   */
  private function backupFile(string $target_uri, string $backup_uri): bool {
    if (!is_file($target_uri)) {
      return FALSE;
    }
    if (!@rename($target_uri, $backup_uri)) {
      throw InvalidRuntimeProfileException::writeFailed();
    }
    return TRUE;
  }

  /**
   * Restores original files and removes staged data after activation failure.
   *
   * @param string $directory
   *   The gateway directory.
   * @param array<string, string> $activated
   *   Newly activated targets.
   * @param array<string, string> $backups
   *   Original target backups.
   * @param array<string, string> $temporary_files
   *   Remaining staged files.
   */
  private function restoreFiles(
    string $directory,
    array $activated,
    array $backups,
    array $temporary_files,
  ): void {
    foreach ($activated as $target_uri) {
      @unlink($target_uri);
    }
    foreach ($backups as $filename => $backup_uri) {
      @rename($backup_uri, $directory . '/' . $filename);
    }
    foreach ($temporary_files as $temporary_uri) {
      @unlink($temporary_uri);
    }
  }

  /**
   * Writes one protected temporary file before activation under the lock.
   */
  private function stageFile(
    string $directory,
    string $filename,
    #[\SensitiveParameter]
    string $contents,
    string $token,
  ): string {
    $temporary_uri = $directory . '/.' . $filename . '.tmp-' . $token;
    // Drupal local stream wrappers do not support LOCK_EX. The unique name
    // and cooperative gateway lock protect the write before atomic rename.
    $written = @file_put_contents($temporary_uri, $contents);
    if ($written !== strlen($contents) || !@chmod($temporary_uri, 0600)) {
      @unlink($temporary_uri);
      throw InvalidRuntimeProfileException::writeFailed();
    }
    return $temporary_uri;
  }

  /**
   * Reads one size-limited file.
   *
   * @param string $uri
   *   The local file URI.
   * @param positive-int $maximum_bytes
   *   The maximum number of bytes to accept.
   */
  private function readFile(string $uri, int $maximum_bytes): ?string {
    $contents = @file_get_contents($uri, FALSE, NULL, 0, $maximum_bytes + 1);
    if (
      !is_string($contents)
      || trim($contents) === ''
      || strlen($contents) > $maximum_bytes
    ) {
      return NULL;
    }

    return $contents;
  }

  /**
   * Gets the stable private directory for one gateway UUID.
   */
  private function getDirectory(string $gateway_uuid): string {
    return rtrim($this->private_base_uri, '/') . '/' . $gateway_uuid;
  }

  /**
   * Rejects traversal and non-UUID gateway identifiers.
   */
  private function assertGatewayUuid(string $gateway_uuid): void {
    if (
      preg_match(
        '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/Di',
        $gateway_uuid,
      ) !== 1
    ) {
      throw InvalidRuntimeProfileException::invalidProfile();
    }
  }

  /**
   * Acquires a short-lived profile lock.
   */
  private function acquireLock(string $lock_name, float $timeout = 30.0): bool {
    if ($this->lock->acquire($lock_name, $timeout)) {
      return TRUE;
    }

    $this->lock->wait($lock_name, 5);
    return $this->lock->acquire($lock_name, $timeout);
  }

}
