<?php

declare(strict_types=1);

namespace Drupal\Tests\commerce_novapay\Unit\Runtime;

use Drupal\Core\Lock\NullLockBackend;
use Drupal\commerce_novapay\Credential\CredentialResolver;
use Drupal\commerce_novapay\Credential\Credentials;
use Drupal\commerce_novapay\Credential\NovaPayMode;
use Drupal\commerce_novapay\Credential\RsaKeyValidator;
use Drupal\commerce_novapay\Credential\SandboxCredentialProvider;
use Drupal\commerce_novapay\Exception\InvalidRuntimeProfileException;
use Drupal\commerce_novapay\Runtime\CredentialChangeGuardInterface;
use Drupal\commerce_novapay\Runtime\RuntimeConfiguration;
use Drupal\commerce_novapay\Runtime\RuntimeProfile;
use Drupal\commerce_novapay\Runtime\RuntimeProfileStorage;
use Drupal\commerce_novapay\Runtime\TransactionMode;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Tests environment-local NovaPay runtime profile storage.
 */
#[CoversClass(RuntimeProfile::class)]
#[CoversClass(RuntimeProfileStorage::class)]
#[CoversClass(RuntimeConfiguration::class)]
#[CoversClass(SandboxCredentialProvider::class)]
#[CoversClass(RsaKeyValidator::class)]
#[Group('commerce_novapay')]
final class RuntimeProfileStorageTest extends TestCase {

  private const string GATEWAY_UUID = '3ea2ebba-25b0-4d3b-b09d-fb462986cb70';

  /**
   * The isolated private filesystem root.
   */
  private string $temporaryDirectory;

  /**
   * The gateway profile directory.
   */
  private string $gatewayDirectory;

  /**
   * The tested storage.
   */
  private RuntimeProfileStorage $storage;

  /**
   * The shared RSA validator.
   */
  private RsaKeyValidator $validator;

  /**
   * The packaged sandbox credential provider.
   */
  private SandboxCredentialProvider $sandboxCredentials;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->temporaryDirectory = sys_get_temp_dir()
      . '/commerce_novapay-runtime-'
      . bin2hex(random_bytes(8));
    $this->gatewayDirectory = $this->temporaryDirectory
      . '/'
      . self::GATEWAY_UUID;
    $this->validator = new RsaKeyValidator();
    $this->sandboxCredentials = new SandboxCredentialProvider($this->validator);
    $this->storage = new RuntimeProfileStorage(
      $this->validator,
      new NullLockBackend(),
      $this->temporaryDirectory,
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    if (is_dir($this->gatewayDirectory)) {
      $entries = scandir($this->gatewayDirectory);
      self::assertIsArray($entries);
      foreach ($entries as $entry) {
        if ($entry !== '.' && $entry !== '..') {
          unlink($this->gatewayDirectory . '/' . $entry);
        }
      }
      rmdir($this->gatewayDirectory);
    }
    if (is_dir($this->temporaryDirectory)) {
      rmdir($this->temporaryDirectory);
    }

    parent::tearDown();
  }

  /**
   * Tests that test settings contain no merchant ID or key references.
   */
  public function testSavesMinimalTestProfile(): void {
    $profile = $this->createProfile(NovaPayMode::Test);
    $this->storage->save(self::GATEWAY_UUID, $profile);

    $loaded = $this->storage->load(self::GATEWAY_UUID);
    self::assertEquals($profile, $loaded);
    self::assertFileExists($this->gatewayDirectory . '/settings.json');
    self::assertFileDoesNotExist($this->gatewayDirectory . '/private.pem');
    self::assertFileDoesNotExist($this->gatewayDirectory . '/public.pem');

    $json = file_get_contents($this->gatewayDirectory . '/settings.json');
    self::assertIsString($json);
    self::assertStringNotContainsString('merchant_id', $json);
    self::assertStringNotContainsString('private', $json);
    self::assertStringNotContainsString('public', $json);
    self::assertSame(
      0600,
      fileperms($this->gatewayDirectory . '/settings.json') & 0777,
    );
  }

  /**
   * Tests that the automatic success return delay is persisted locally.
   */
  public function testSavesConfiguredSuccessRedirectTimeout(): void {
    $profile = new RuntimeProfile(
      NovaPayMode::Test,
      NULL,
      TransactionMode::Direct,
      '',
      FALSE,
      12,
    );
    $this->storage->save(self::GATEWAY_UUID, $profile);

    $loaded = $this->storage->load(self::GATEWAY_UUID);
    self::assertInstanceOf(RuntimeProfile::class, $loaded);
    self::assertSame(12, $loaded->getSuccessRedirectTimeout());
  }

  /**
   * Tests the documented int32 boundary and zero omission sentinel.
   */
  public function testAcceptsSuccessRedirectTimeoutBoundaries(): void {
    $disabled = new RuntimeProfile(
      NovaPayMode::Test,
      NULL,
      TransactionMode::Direct,
      '',
      FALSE,
      0,
    );
    $maximum = new RuntimeProfile(
      NovaPayMode::Test,
      NULL,
      TransactionMode::Direct,
      '',
      FALSE,
      RuntimeProfile::MAX_SUCCESS_REDIRECT_TIMEOUT,
    );

    self::assertSame(0, $disabled->getSuccessRedirectTimeout());
    self::assertSame(
      RuntimeProfile::MAX_SUCCESS_REDIRECT_TIMEOUT,
      $maximum->getSuccessRedirectTimeout(),
    );
  }

  /**
   * Tests rejection of redirect timeouts outside NovaPay's int32 shape.
   */
  #[DataProvider('invalidSuccessRedirectTimeoutProvider')]
  public function testRejectsInvalidSuccessRedirectTimeout(int $timeout): void {
    $this->expectException(InvalidRuntimeProfileException::class);
    new RuntimeProfile(
      NovaPayMode::Test,
      NULL,
      TransactionMode::Direct,
      '',
      FALSE,
      $timeout,
    );
  }

  /**
   * Provides redirect timeouts outside the supported int32 range.
   *
   * @return iterable<string, array{int}>
   *   Invalid timeout values.
   */
  public static function invalidSuccessRedirectTimeoutProvider(): iterable {
    yield 'negative' => [-1];
    yield 'larger than int32' => [2147483648];
  }

  /**
   * Tests live profile and two unrelated RSA key files.
   */
  public function testSavesAndResolvesLiveCredentials(): void {
    $private_key = $this->createPrivateKey();
    $public_key = $this->createPublicKey();
    $profile = $this->createProfile(NovaPayMode::Live);

    $this->storage->save(
      self::GATEWAY_UUID,
      $profile,
      $private_key,
      $public_key,
    );

    $resolver = new CredentialResolver(
      $this->validator,
      $this->sandboxCredentials,
      new NullLockBackend(),
      $this->temporaryDirectory,
    );
    $configuration = $resolver->resolveRuntimeConfiguration(
      self::GATEWAY_UUID,
    );
    $credentials = $configuration->getCredentials();

    self::assertEquals($profile, $configuration->getProfile());
    self::assertSame(
      'https://api-ecom.novapay.ua',
      $configuration->getApiBaseUrl(),
    );
    self::assertSame('merchant-live', $credentials->getMerchantId());
    self::assertSame($private_key, $credentials->getPrivateKeyPem());
    self::assertSame($public_key, $credentials->getPublicKeyPem());
    self::assertTrue($this->storage->hasValidLiveKeys(self::GATEWAY_UUID));
  }

  /**
   * Tests rotation without changing stable filenames or leaving staged files.
   */
  public function testAtomicallyRotatesBothLiveKeys(): void {
    $profile = $this->createProfile(NovaPayMode::Live);
    $this->storage->save(
      self::GATEWAY_UUID,
      $profile,
      $this->createPrivateKey(),
      $this->createPublicKey(),
    );

    $new_private_key = $this->createPrivateKey();
    $new_public_key = $this->createPublicKey();
    $this->storage->save(
      self::GATEWAY_UUID,
      $profile,
      $new_private_key,
      $new_public_key,
    );

    self::assertSame(
      $new_private_key,
      file_get_contents($this->gatewayDirectory . '/private.pem'),
    );
    self::assertSame(
      $new_public_key,
      file_get_contents($this->gatewayDirectory . '/public.pem'),
    );
    self::assertSame(
      ['.', '..', 'private.pem', 'public.pem', 'settings.json'],
      scandir($this->gatewayDirectory),
    );
  }

  /**
   * Tests rollback when a later key cannot replace an unexpected directory.
   */
  public function testFailedActivationRestoresOriginalFiles(): void {
    $profile = $this->createProfile(NovaPayMode::Live);
    $private_key = $this->createPrivateKey();
    $this->storage->save(self::GATEWAY_UUID, $profile, $private_key, $this->createPublicKey());
    $settings = file_get_contents($this->gatewayDirectory . '/settings.json');
    unlink($this->gatewayDirectory . '/public.pem');
    mkdir($this->gatewayDirectory . '/public.pem');
    try {
      try {
        $this->storage->save(self::GATEWAY_UUID, $profile, $this->createPrivateKey(), $this->createPublicKey());
        self::fail('A directory cannot be replaced by a key file.');
      }
      catch (InvalidRuntimeProfileException) {
        self::assertSame($private_key, file_get_contents($this->gatewayDirectory . '/private.pem'));
        self::assertSame($settings, file_get_contents($this->gatewayDirectory . '/settings.json'));
        self::assertSame(['.', '..', 'private.pem', 'public.pem', 'settings.json'], scandir($this->gatewayDirectory));
      }
    }
    finally {
      rmdir($this->gatewayDirectory . '/public.pem');
    }
  }

  /**
   * Tests locked reads reject corrupt and oversized settings safely.
   */
  public function testRejectsCorruptStoredProfile(): void {
    $this->storage->save(self::GATEWAY_UUID, $this->createProfile(NovaPayMode::Test));
    foreach (['{', 'null', str_repeat('a', 65537)] as $contents) {
      file_put_contents($this->gatewayDirectory . '/settings.json', $contents);
      try {
        $this->storage->load(self::GATEWAY_UUID);
        self::fail('Invalid stored profile must be rejected.');
      }
      catch (InvalidRuntimeProfileException) {
        self::assertFalse($this->storage->hasValidLiveKeys(self::GATEWAY_UUID));
      }
    }
  }

  /**
   * Tests that one uploaded key cannot partially replace live credentials.
   */
  public function testRejectsIncompleteRotationWithoutChangingFiles(): void {
    $profile = $this->createProfile(NovaPayMode::Live);
    $private_key = $this->createPrivateKey();
    $public_key = $this->createPublicKey();
    $this->storage->save(
      self::GATEWAY_UUID,
      $profile,
      $private_key,
      $public_key,
    );

    try {
      $this->storage->save(
        self::GATEWAY_UUID,
        $profile,
        $this->createPrivateKey(),
      );
      self::fail('An incomplete key rotation must be rejected.');
    }
    catch (InvalidRuntimeProfileException $exception) {
      self::assertSame(
        'Upload both NovaPay key files together.',
        $exception->getMessage(),
      );
    }

    self::assertSame(
      $private_key,
      file_get_contents($this->gatewayDirectory . '/private.pem'),
    );
    self::assertSame(
      $public_key,
      file_get_contents($this->gatewayDirectory . '/public.pem'),
    );
  }

  /**
   * Tests that valid shared sandbox-issued keys are accepted as live uploads.
   */
  public function testAcceptsValidSharedSandboxKeysInLiveMode(): void {
    $sandbox_credentials = $this->sandboxCredentials->getCredentials();
    $this->storage->save(
      self::GATEWAY_UUID,
      $this->createProfile(NovaPayMode::Live),
      $sandbox_credentials->getPrivateKeyPem(),
      $sandbox_credentials->getPublicKeyPem(),
    );

    $resolver = new CredentialResolver(
      $this->validator,
      $this->sandboxCredentials,
      new NullLockBackend(),
      $this->temporaryDirectory,
    );
    $configuration = $resolver->resolveRuntimeConfiguration(
      self::GATEWAY_UUID,
    );

    self::assertSame(
      NovaPayMode::Live,
      $configuration->getCredentials()->getMode(),
    );
    self::assertSame(
      'https://api-ecom.novapay.ua',
      $configuration->getApiBaseUrl(),
    );
  }

  /**
   * Tests exact cleanup of a removed gateway's local profile.
   */
  public function testDeletesGatewayRuntimeDirectory(): void {
    $this->storage->save(
      self::GATEWAY_UUID,
      $this->createProfile(NovaPayMode::Live),
      $this->createPrivateKey(),
      $this->createPublicKey(),
    );
    self::assertDirectoryExists($this->gatewayDirectory);

    $this->storage->delete(self::GATEWAY_UUID);

    self::assertDirectoryDoesNotExist($this->gatewayDirectory);
    self::assertDirectoryExists($this->temporaryDirectory);
  }

  /**
   * Tests that endpoint and credentials must use the same environment.
   */
  public function testRuntimeConfigurationBindsModeToEndpoint(): void {
    $test_profile = $this->createProfile(NovaPayMode::Test);
    $test_credentials = new Credentials(
      NovaPayMode::Test,
      '2',
      'private',
      'public',
    );
    $configuration = new RuntimeConfiguration(
      $test_profile,
      $test_credentials,
    );

    self::assertSame(
      'https://api-qecom.novapay.ua',
      $configuration->getApiBaseUrl(),
    );

    $this->expectException(InvalidRuntimeProfileException::class);
    new RuntimeConfiguration(
      $test_profile,
      new Credentials(
        NovaPayMode::Live,
        'merchant-live',
        'private',
        'public',
      ),
    );
  }

  /**
   * Tests independent credentials, mode switching, retention and cleanup.
   */
  public function testCustomSandboxCredentialsRemainIsolatedFromLive(): void {
    $live = $this->createProfile(NovaPayMode::Live);
    $live_private = $this->createPrivateKey();
    $live_public = $this->createPublicKey();
    $this->storage->save(self::GATEWAY_UUID, $live, $live_private, $live_public);
    $custom = new RuntimeProfile(NovaPayMode::Test, 'merchant-live', TransactionMode::Direct, '', FALSE, 5, TRUE, 'merchant-test');
    $test_private = $this->createPrivateKey();
    $test_public = $this->createPublicKey();
    $this->storage->save(self::GATEWAY_UUID, $custom, $test_private, $test_public);
    $this->storage->save(self::GATEWAY_UUID, $custom);
    self::assertTrue($this->storage->hasValidTestKeys(self::GATEWAY_UUID));
    self::assertTrue($this->storage->hasValidLiveKeys(self::GATEWAY_UUID));
    $loaded = $this->storage->load(self::GATEWAY_UUID);
    self::assertInstanceOf(RuntimeProfile::class, $loaded);
    self::assertNull($loaded->getMerchantId());
    self::assertSame('merchant-live', $loaded->getLiveMerchantId());
    self::assertSame('merchant-test', $loaded->getTestMerchantId());
    self::assertTrue($loaded->usesCustomTestCredentials());
    $resolver = new CredentialResolver($this->validator, $this->sandboxCredentials, new NullLockBackend(), $this->temporaryDirectory);
    $runtime = $resolver->resolveRuntimeConfiguration(self::GATEWAY_UUID);
    self::assertSame('https://api-qecom.novapay.ua', $runtime->getApiBaseUrl());
    self::assertSame('merchant-test', $runtime->getCredentials()->getMerchantId());
    self::assertTrue($test_private === $runtime->getCredentials()->getPrivateKeyPem());
    self::assertTrue($test_public === $runtime->getCredentials()->getPublicKeyPem());
    $disabled = new RuntimeProfile(NovaPayMode::Test, 'merchant-live', TransactionMode::Direct, '', FALSE, 5, FALSE, 'merchant-test');
    $this->storage->save(self::GATEWAY_UUID, $disabled);
    self::assertSame('2', $resolver->resolveRuntimeConfiguration(self::GATEWAY_UUID)->getCredentials()->getMerchantId());
    self::assertTrue($this->storage->hasValidTestKeys(self::GATEWAY_UUID));
    $retained_live = new RuntimeProfile(NovaPayMode::Live, 'merchant-live', TransactionMode::Direct, '', FALSE, 5, TRUE, 'merchant-test');
    $this->storage->save(self::GATEWAY_UUID, $retained_live);
    $resolved_live = $resolver->resolveRuntimeConfiguration(self::GATEWAY_UUID);
    self::assertTrue($live_private === $resolved_live->getCredentials()->getPrivateKeyPem());
    self::assertTrue($live_public === $resolved_live->getCredentials()->getPublicKeyPem());
    self::assertTrue($resolved_live->getProfile()->usesCustomTestCredentials());
    $this->storage->delete(self::GATEWAY_UUID);
    self::assertDirectoryDoesNotExist($this->gatewayDirectory);
  }

  /**
   * Tests a missing sandbox key prevents activation and preserves the profile.
   */
  public function testRejectsCustomSandboxWithoutCompleteKeys(): void {
    $original = $this->createProfile(NovaPayMode::Test);
    $this->storage->save(self::GATEWAY_UUID, $original);
    $custom = new RuntimeProfile(NovaPayMode::Test, NULL, TransactionMode::Direct, '', FALSE, 5, TRUE, 'merchant-test');
    try {
      $this->storage->save(self::GATEWAY_UUID, $custom);
      self::fail('Custom sandbox credentials cannot fall back to public keys.');
    }
    catch (InvalidRuntimeProfileException) {
      self::assertEquals($original, $this->storage->load(self::GATEWAY_UUID));
      self::assertFalse($this->storage->hasValidTestKeys(self::GATEWAY_UUID));
    }
  }

  /**
   * Tests a rejected identity change cannot alter active credentials/settings.
   */
  public function testGuardRejectionPreservesStoredFiles(): void {
    $original = $this->createProfile(NovaPayMode::Test);
    $this->storage->save(self::GATEWAY_UUID, $original);
    $guard = $this->createMock(CredentialChangeGuardInterface::class);
    $guard->expects(self::once())->method('assertChangeAllowed')
      ->with(self::GATEWAY_UUID, $original, self::isInstanceOf(RuntimeProfile::class), TRUE)
      ->willThrowException(InvalidRuntimeProfileException::invalidProfile());
    $storage = new RuntimeProfileStorage($this->validator, new NullLockBackend(), $this->temporaryDirectory, $guard);
    $custom = new RuntimeProfile(NovaPayMode::Test, NULL, TransactionMode::Direct, '', FALSE, 5, TRUE, 'merchant-test');
    try {
      $storage->save(self::GATEWAY_UUID, $custom, $this->createPrivateKey(), $this->createPublicKey());
      self::fail('Guard rejection must prevent activation.');
    }
    catch (InvalidRuntimeProfileException) {
      self::assertEquals($original, $storage->load(self::GATEWAY_UUID));
      self::assertFileDoesNotExist($this->gatewayDirectory . '/test-private.pem');
      self::assertFileDoesNotExist($this->gatewayDirectory . '/test-public.pem');
    }
  }

  /**
   * Tests strict new fields and backward-compatible profile decoding.
   */
  public function testCustomSandboxProfileValidation(): void {
    $values = $this->createProfile(NovaPayMode::Test)->toArray();
    unset($values['custom_test_credentials']);
    self::assertFalse(RuntimeProfile::fromArray($values)->usesCustomTestCredentials());
    $invalid_values = [
      ['custom_test_credentials' => 1],
      ['test_merchant_id' => 42],
      ['custom_test_credentials' => TRUE, 'test_merchant_id' => ' '],
    ];
    foreach ($invalid_values as $invalid) {
      try {
        RuntimeProfile::fromArray(array_replace($values, $invalid));
        self::fail('Malformed custom sandbox settings must be rejected.');
      }
      catch (InvalidRuntimeProfileException $exception) {
        self::assertSame('The local NovaPay settings are invalid.', $exception->getMessage());
      }
    }
  }

  /**
   * Creates a test or live runtime profile.
   */
  private function createProfile(NovaPayMode $mode): RuntimeProfile {
    return new RuntimeProfile(
      $mode,
      $mode === NovaPayMode::Live ? 'merchant-live' : NULL,
      TransactionMode::Hold,
      '31316718',
      FALSE,
    );
  }

  /**
   * Generates an ephemeral RSA private key.
   */
  private function createPrivateKey(): string {
    $key = openssl_pkey_new([
      'private_key_bits' => 2048,
      'private_key_type' => OPENSSL_KEYTYPE_RSA,
    ]);
    self::assertNotFalse($key);

    $exported = openssl_pkey_export($key, $private_key);
    self::assertTrue($exported);
    self::assertIsString($private_key);

    return $private_key;
  }

  /**
   * Generates an unrelated ephemeral RSA public key.
   */
  private function createPublicKey(): string {
    $key = openssl_pkey_new([
      'private_key_bits' => 2048,
      'private_key_type' => OPENSSL_KEYTYPE_RSA,
    ]);
    self::assertNotFalse($key);
    $details = openssl_pkey_get_details($key);
    self::assertIsArray($details);
    self::assertIsString($details['key']);

    return $details['key'];
  }

}
