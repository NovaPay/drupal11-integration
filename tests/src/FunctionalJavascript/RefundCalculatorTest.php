<?php

declare(strict_types=1);

namespace Drupal\Tests\commerce_novapay\FunctionalJavascript;

use Behat\Mink\Driver\Selenium2Driver;
use Drupal\FunctionalJavascriptTests\WebDriverTestBase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests exact decimal item-level refund calculations in a browser.
 */
#[Group('commerce_novapay')]
final class RefundCalculatorTest extends WebDriverTestBase {

  /**
   * {@inheritdoc}
   *
   * @var list<string>
   */
  protected static $modules = ['commerce_novapay_test'];

  /**
   * {@inheritdoc}
   *
   * @var string
   */
  protected $defaultTheme = 'stark';

  /**
   * Tests line totals, aggregate total, and invalid-input fallback.
   */
  public function testExactDecimalCalculation(): void {
    $this->drupalGet('/commerce-novapay-test/refund-calculator');
    $page = $this->getSession()->getPage();
    $inputs = $page->findAll('css', '.commerce-novapay-refund-quantity');
    self::assertCount(2, $inputs);

    $this->getSession()->executeScript(
      "const inputs = document.querySelectorAll('.commerce-novapay-refund-quantity'); inputs[0].value = '2'; inputs[0].dispatchEvent(new Event('input', {bubbles: true})); inputs[1].value = '0.5'; inputs[1].dispatchEvent(new Event('input', {bubbles: true}));",
    );

    $this->assertJsCondition(
      "document.querySelector('.commerce-novapay-refund-total').textContent === '22.2 UAH'",
      5000,
    );
    self::assertSame(
      '20.5 UAH',
      $page->find('css', '[data-test-item="11"]')?->getText(),
    );
    self::assertSame(
      '1.7 UAH',
      $page->find('css', '[data-test-item="12"]')?->getText(),
    );

    $this->getSession()->executeScript(
      "const input = document.querySelector('.commerce-novapay-refund-quantity'); input.value = 'invalid'; input.dispatchEvent(new Event('input', {bubbles: true}));",
    );
    $this->assertJsCondition(
      "document.querySelector('.commerce-novapay-refund-total').textContent === '1.7 UAH'",
      5000,
    );
  }

  /**
   * Tests mode isolation, keyboard selection, and exact monetary totals.
   */
  public function testRefundModes(): void {
    $this->drupalGet('/commerce-novapay-test/refund-calculator');
    $page = $this->getSession()->getPage();
    $this->assertJsCondition(
      "document.querySelector('.commerce-novapay-refund-amount').disabled",
    );
    self::assertTrue($page->find('css', '.commerce-novapay-refund-items')?->isVisible());
    self::assertFalse($page->find('css', '.commerce-novapay-refund-amount')?->isVisible());
    $page->fillField('items[11][quantity]', '2');
    $this->assertJsCondition(
      "document.querySelector('.commerce-novapay-refund-total').textContent === '20.5 UAH'",
    );
    $this->captureEvidence('refund-by-items.png');

    // Use native radio keyboard navigation, preserving its accessible behavior.
    $items = $page->find('css', 'input[name="refund_mode"][value="items"]');
    self::assertNotNull($items);
    $items->click();
    $driver = $this->getSession()->getDriver();
    self::assertInstanceOf(Selenium2Driver::class, $driver);
    $driver->getWebDriverSession()->keys(['value' => ["\u{E014}"]]);
    $this->assertJsCondition(
      "document.querySelector('input[name=refund_mode][value=amount]').checked",
    );
    $this->assertJsCondition("document.querySelector('.commerce-novapay-refund-items').offsetParent === null && document.querySelector('.commerce-novapay-refund-amount').offsetParent !== null");
    self::assertFalse($page->find('css', '.commerce-novapay-refund-items')?->isVisible());
    self::assertTrue($page->find('css', '.commerce-novapay-refund-amount')?->isVisible());
    $this->assertJsCondition(
      "Array.from(document.querySelectorAll('.commerce-novapay-refund-quantity')).every(input => input.disabled)",
    );
    $page->fillField('amount', '200.01');
    $this->assertJsCondition(
      "document.querySelector('.commerce-novapay-refund-total').textContent === '200.01 UAH'",
    );
    $this->captureEvidence('refund-by-amount.png');
    $page->fillField('amount', '0.01');
    $this->assertJsCondition(
      "document.querySelector('.commerce-novapay-refund-total').textContent === '0.01 UAH'",
    );
    foreach (['1e2', 'NaN', 'Infinity', '0.001', '-1'] as $invalid) {
      $page->fillField('amount', $invalid);
      $this->assertJsCondition(
        "document.querySelector('.commerce-novapay-refund-total').textContent === '0 UAH'",
      );
    }
    $page->selectFieldOption('refund_mode', 'items');
    $this->assertJsCondition(
      "document.querySelector('.commerce-novapay-refund-total').textContent === '20.5 UAH'",
    );
    $this->assertJsCondition(
      "document.querySelector('.commerce-novapay-refund-amount').disabled && Array.from(document.querySelectorAll('.commerce-novapay-refund-quantity')).every(input => !input.disabled)",
    );
  }

  /**
   * Captures optional synthetic UI evidence outside the module release files.
   */
  private function captureEvidence(string $filename): void {
    $directory = getenv('NOVAPAY_SCREENSHOT_DIR');
    if (is_string($directory) && $directory !== '' && is_dir($directory)) {
      $this->getSession()->resizeWindow(1100, 800);
      $this->createScreenshot($directory . '/' . $filename);
    }
  }

}
