<?php

declare(strict_types=1);

namespace Drupal\Tests\commerce_novapay\Unit\Runtime;

use Drupal\Core\Lock\NullLockBackend;
use Drupal\commerce_novapay\Api\Dto\Request\CreateSessionRequest;
use Drupal\commerce_novapay\Api\NovaPayApiClient;
use Drupal\commerce_novapay\Credential\CredentialResolver;
use Drupal\commerce_novapay\Credential\NovaPayMode;
use Drupal\commerce_novapay\Credential\RsaKeyValidator;
use Drupal\commerce_novapay\Credential\SandboxCredentialProvider;
use Drupal\commerce_novapay\Logging\NovaPayLoggerInterface;
use Drupal\commerce_novapay\Postback\Parser\V2PostbackParser;
use Drupal\commerce_novapay\Runtime\RuntimeConfigurationProviderInterface;
use Drupal\commerce_novapay\Runtime\RuntimeProfile;
use Drupal\commerce_novapay\Runtime\RuntimeProfileStorage;
use Drupal\commerce_novapay\Runtime\TransactionMode;
use Drupal\commerce_novapay\Signature\RsaSha256Signer;
use Drupal\commerce_novapay\Signature\RsaSha256Verifier;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Integrates stored custom credentials with real RSA and mocked HTTP only.
 */
#[CoversClass(CredentialResolver::class)]
#[CoversClass(NovaPayApiClient::class)]
#[CoversClass(RsaSha256Signer::class)]
#[CoversClass(RsaSha256Verifier::class)]
#[Group('commerce_novapay')]
final class CustomSandboxCryptoIntegrationTest extends TestCase {

  /**
   * Tests merchant signing and independent NovaPay callback verification.
   */
  public function testStoredKeysSignRequestsAndVerifyV2Callback(): void {
    $uuid = '3ea2ebba-25b0-4d3b-b09d-fb462986cb70';
    $directory = sys_get_temp_dir() . '/novapay-crypto-' . bin2hex(random_bytes(8));
    $validator = new RsaKeyValidator();
    $storage = new RuntimeProfileStorage($validator, new NullLockBackend(), $directory);
    [$merchant_private, $merchant_public] = $this->generateKeys();
    [$novapay_private, $novapay_public] = $this->generateKeys();
    try {
      $profile = new RuntimeProfile(NovaPayMode::Test, NULL, TransactionMode::Direct, '', FALSE, 5, TRUE, 'custom-sandbox-merchant');
      $storage->save($uuid, $profile, $merchant_private, $novapay_public);
      $resolver = new CredentialResolver($validator, new SandboxCredentialProvider($validator), new NullLockBackend(), $directory);
      $runtime = $resolver->resolveRuntimeConfiguration($uuid);
      $gateway = $this->createMock(RuntimeConfigurationProviderInterface::class);
      $gateway->method('getRuntimeConfiguration')->willReturn($runtime);
      $history = [];
      $stack = HandlerStack::create(new MockHandler([new Response(200, [], '{"id":"custom-session"}')]));
      $stack->push(Middleware::history($history));
      $signer = new RsaSha256Signer($validator);
      $client = new NovaPayApiClient(new Client(['handler' => $stack]), $signer, $this->createMock(NovaPayLoggerInterface::class));
      self::assertSame('custom-session', $client->createSession($gateway, new CreateSessionRequest('+380501234567'))->getSessionId());
      self::assertIsArray($history);
      self::assertCount(1, $history);
      $request = $history[0]['request'];
      $body = (string) $request->getBody();
      self::assertSame('https://api-qecom.novapay.ua/v1/session', (string) $request->getUri());
      self::assertSame('custom-sandbox-merchant', json_decode($body, TRUE, 16, JSON_THROW_ON_ERROR)['merchant_id']);
      $signature = base64_decode($request->getHeaderLine('x-sign'), TRUE);
      self::assertIsString($signature);
      self::assertSame(1, openssl_verify($body, $signature, $merchant_public, OPENSSL_ALGO_SHA256));
      self::assertSame(0, openssl_verify($body, $signature, $novapay_public, OPENSSL_ALGO_SHA256));

      $callback = '{"id":"custom-session","status":"paid","payments":[{"external_id":"order-1","amount":10}]}';
      $callback_signature = $signer->sign($callback, $novapay_private);
      $verifier = new RsaSha256Verifier($validator);
      $stored_public = $runtime->getCredentials()->getPublicKeyPem();
      self::assertTrue($verifier->verify($callback, $callback_signature, $stored_public));
      self::assertFalse($verifier->verify($callback . ' ', $callback_signature, $stored_public));
      self::assertFalse($verifier->verify($callback, $signer->sign($callback, $merchant_private), $stored_public));
      $parser = new V2PostbackParser();
      $payload = json_decode($callback, TRUE, 16, JSON_THROW_ON_ERROR);
      self::assertTrue($parser->supports($payload));
      self::assertSame('custom-session', $parser->parse($payload)->getSessionId());
    }
    finally {
      $storage->delete($uuid);
      if (is_dir($directory)) {
        rmdir($directory);
      }
    }
  }

  /**
   * Generates ephemeral, unrelated merchant and NovaPay RSA material.
   *
   * @return array{string, string}
   *   A private key and its corresponding public key.
   */
  private function generateKeys(): array {
    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    self::assertNotFalse($key);
    self::assertTrue(openssl_pkey_export($key, $private));
    $details = openssl_pkey_get_details($key);
    self::assertIsArray($details);
    return [$private, $details['key']];
  }

}
