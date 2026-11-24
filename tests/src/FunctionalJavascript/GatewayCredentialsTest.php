<?php

declare(strict_types=1);

namespace Drupal\Tests\commerce_novapay\FunctionalJavascript;

use Drupal\Tests\commerce\FunctionalJavascript\CommerceWebDriverTestBase;
use Drupal\commerce_payment\Entity\PaymentGateway;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests sandbox credential conditional visibility in the real gateway form.
 */
#[Group('commerce_novapay')]
final class GatewayCredentialsTest extends CommerceWebDriverTestBase {

  /**
   * {@inheritdoc}
   *
   * @var list<string>
   */
  protected static $modules = ['commerce_novapay_test'];

  /**
   * {@inheritdoc}
   */
  protected function getAdministratorPermissions(): array {
    return array_merge(parent::getAdministratorPermissions(), [
      'administer commerce_payment_gateway',
    ]);
  }

  /**
   * Tests checkbox and conditional fields across both runtime modes.
   */
  public function testCustomSandboxVisibility(): void {
    $gateway = PaymentGateway::create([
      'id' => 'novapay_visibility',
      'label' => 'NovaPay visibility',
      'plugin' => 'novapay',
    ]);
    $gateway->save();
    $this->drupalGet($gateway->toUrl('edit-form'));
    $page = $this->getSession()->getPage();
    $prefix = 'configuration[novapay][runtime_settings]';
    $checkbox = $page->findField($prefix . '[custom_test_credentials]');
    $merchant = $page->findField($prefix . '[custom_test][merchant_id]');
    $private = $page->findField('files[novapay_test_private_key_upload]');
    $public = $page->findField('files[novapay_test_public_key_upload]');
    self::assertNotNull($checkbox);
    self::assertNotNull($merchant);
    self::assertNotNull($private);
    self::assertNotNull($public);
    self::assertTrue($checkbox->isVisible());
    self::assertFalse($merchant->isVisible());
    $checkbox->check();
    foreach ([$merchant, $private, $public] as $field) {
      self::assertTrue($field->isVisible());
    }
    $directory = getenv('NOVAPAY_SCREENSHOT_DIR');
    if (is_string($directory) && is_dir($directory)) {
      $this->getSession()->resizeWindow(1100, 1000);
      $this->createScreenshot($directory . '/custom-test-credentials.png');
    }
    $page->selectFieldOption($prefix . '[runtime_mode]', 'live');
    self::assertFalse($checkbox->isVisible());
    foreach ([$merchant, $private, $public] as $field) {
      self::assertFalse($field->isVisible());
    }
    $page->selectFieldOption($prefix . '[runtime_mode]', 'test');
    self::assertTrue($checkbox->isVisible());
    self::assertTrue($merchant->isVisible());
    $checkbox->uncheck();
    self::assertFalse($merchant->isVisible());
  }

}
