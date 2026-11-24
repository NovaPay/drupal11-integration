<?php

declare(strict_types=1);

namespace Drupal\commerce_novapay\Form;

use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\commerce_novapay\Credential\NovaPayMode;
use Drupal\commerce_novapay\Runtime\RuntimeProfile;
use Drupal\commerce_novapay\Runtime\TransactionMode;

/**
 * Builds environment-local gateway settings without reading credential files.
 */
final class RuntimeSettingsFormBuilder {

  use StringTranslationTrait;

  /**
   * Builds the environment-local settings section.
   *
   * @param \Drupal\commerce_novapay\Runtime\RuntimeProfile|null $profile
   *   The local profile, when available.
   * @param bool $profile_error
   *   Whether loading the local profile failed.
   * @param bool|null $has_live_keys
   *   Whether live keys are installed, or NULL when unavailable.
   * @param bool $has_test_keys
   *   Whether custom sandbox keys are installed.
   *
   * @return array<string, mixed>
   *   The settings form section.
   */
  public function build(
    ?RuntimeProfile $profile,
    bool $profile_error,
    ?bool $has_live_keys,
    bool $has_test_keys,
  ): array {
    $runtime_mode = $profile?->getMode() ?? NovaPayMode::Test;
    $settings = [
      '#type' => 'details',
      '#title' => $this->t('Environment-local NovaPay settings'),
      '#open' => TRUE,
      '#description' => $this->t(
        'These values are stored in the Drupal private filesystem and are not exported with configuration.',
      ),
    ];
    if ($profile_error) {
      $settings['profile_warning'] = [
        '#type' => 'item',
        '#markup' => $this->t(
          'The current local settings are unavailable. Saving requires writable private storage.',
        ),
      ];
    }

    $settings['runtime_mode'] = [
      '#type' => 'radios',
      '#title' => $this->t('Test Mode'),
      '#options' => [
        NovaPayMode::Test->value => $this->t('Test'),
        NovaPayMode::Live->value => $this->t('Live'),
      ],
      '#default_value' => $runtime_mode->value,
      '#required' => TRUE,
    ];
    $this->addTestCredentials($settings, $profile, $has_test_keys);
    $this->addLiveCredentials($settings, $profile, $has_live_keys);
    $this->addPaymentSettings($settings, $profile);

    return $settings;
  }

  /**
   * Adds sandbox credential settings.
   *
   * @param array<string, mixed> $settings
   *   The settings form section.
   * @param \Drupal\commerce_novapay\Runtime\RuntimeProfile|null $profile
   *   The local profile, when available.
   * @param bool $has_test_keys
   *   Whether custom sandbox keys are installed.
   */
  private function addTestCredentials(
    array &$settings,
    ?RuntimeProfile $profile,
    bool $has_test_keys,
  ): void {
    $settings['test_credentials'] = [
      '#type' => 'item',
      '#title' => $this->t('Sandbox credentials'),
      '#markup' => $this->t(
        'Test mode uses the packaged NovaPay sandbox keys and Merchant ID 2 unless custom test credentials are enabled.',
      ),
      '#states' => [
        'visible' => [
          ':input[name="configuration[novapay][runtime_settings][runtime_mode]"]' => [
            'value' => NovaPayMode::Test->value,
          ],
        ],
      ],
    ];

    $test_mode_state = [
      ':input[name="configuration[novapay][runtime_settings][runtime_mode]"]' => [
        'value' => NovaPayMode::Test->value,
      ],
    ];
    $settings['custom_test_credentials'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Use custom test credentials'),
      '#default_value' => $profile?->usesCustomTestCredentials() ?? FALSE,
      '#states' => ['visible' => $test_mode_state],
    ];
    $settings['custom_test'] = [
      '#type' => 'container',
      '#states' => [
        'visible' => $test_mode_state + [
          ':input[name="configuration[novapay][runtime_settings][custom_test_credentials]"]' => ['checked' => TRUE],
        ],
      ],
    ];
    $settings['custom_test']['merchant_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Test Merchant ID'),
      '#default_value' => $profile?->getTestMerchantId() ?? '',
      '#maxlength' => 128,
    ];
    $settings['custom_test']['keys'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Custom test keys'),
      '#description' => $this->t('Use only credentials issued for the NovaPay sandbox. The public key must belong to NovaPay.'),
      'key_status' => [
        '#type' => 'item',
        '#markup' => $has_test_keys
          ? $this->t('Test keys installed. Leave both uploads empty to retain them, or upload both files together to replace them.')
          : $this->t('Test keys not installed. Upload both test key files together.'),
      ],
    ];
    foreach (['private', 'public'] as $type) {
      $settings['custom_test']['keys'][$type . '_key_upload'] = [
        '#type' => 'file',
        '#parents' => ['novapay_test_' . $type . '_key_upload'],
        '#title' => $type === 'private' ? $this->t('Private Key') : $this->t('Public Key'),
        '#accept' => '.pem,.key,text/plain,application/x-pem-file',
      ];
    }
  }

  /**
   * Adds live credential settings.
   *
   * @param array<string, mixed> $settings
   *   The settings form section.
   * @param \Drupal\commerce_novapay\Runtime\RuntimeProfile|null $profile
   *   The local profile, when available.
   * @param bool|null $has_live_keys
   *   Whether live keys are installed, or NULL when unavailable.
   */
  private function addLiveCredentials(
    array &$settings,
    ?RuntimeProfile $profile,
    ?bool $has_live_keys,
  ): void {
    $settings['live_credentials'] = [
      '#type' => 'container',
      '#states' => [
        'visible' => [
          ':input[name="configuration[novapay][runtime_settings][runtime_mode]"]' => [
            'value' => NovaPayMode::Live->value,
          ],
        ],
      ],
    ];
    $settings['live_credentials']['merchant_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Merchant ID'),
      '#default_value' => $profile?->getLiveMerchantId() ?? '',
      '#maxlength' => 128,
    ];
    $settings['live_credentials']['keys'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Live keys'),
    ];
    $settings['live_credentials']['keys']['key_status'] =
      $this->buildLiveKeyStatus($has_live_keys);
    $settings['live_credentials']['keys']['private_key_upload'] = [
      '#type' => 'file',
      '#parents' => ['novapay_private_key_upload'],
      '#title' => $this->t('Private Key'),
      '#description' => $this->t(
        'Select this file together with the NovaPay public key to install or replace both keys.',
      ),
      '#accept' => '.pem,.key,text/plain,application/x-pem-file',
    ];
    $settings['live_credentials']['keys']['public_key_upload'] = [
      '#type' => 'file',
      '#parents' => ['novapay_public_key_upload'],
      '#title' => $this->t('Public Key'),
      '#description' => $this->t(
        'Select this file together with the merchant private key to install or replace both keys.',
      ),
      '#accept' => '.pem,.key,text/plain,application/x-pem-file',
    ];
  }

  /**
   * Adds payment behavior settings.
   *
   * @param array<string, mixed> $settings
   *   The settings form section.
   * @param \Drupal\commerce_novapay\Runtime\RuntimeProfile|null $profile
   *   The local profile, when available.
   */
  private function addPaymentSettings(
    array &$settings,
    ?RuntimeProfile $profile,
  ): void {
    $settings['transaction_mode'] = [
      '#type' => 'radios',
      '#title' => $this->t('Payment Type'),
      '#options' => [
        TransactionMode::Direct->value => $this->t('Direct'),
        TransactionMode::Hold->value => $this->t('Hold'),
      ],
      '#default_value' => $profile?->getTransactionMode()->value
        ?? TransactionMode::Direct->value,
      '#required' => TRUE,
    ];
    $settings['recipient_identifier'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Recipient identifier'),
      '#description' => $this->t(
        'Optional EDRPOU or tax identifier of a payment recipient different from the merchant.',
      ),
      '#default_value' => $profile?->getRecipientIdentifier() ?? '',
      '#maxlength' => 128,
    ];
    $settings['success_redirect_timeout'] = [
      '#type' => 'number',
      '#title' => $this->t('Success redirect delay'),
      '#description' => $this->t(
        'Seconds NovaPay displays its success page before automatically returning the customer to checkout. Use 0 to omit the automatic redirect timeout. Changes apply only to newly created NovaPay sessions.',
      ),
      '#default_value' => $profile?->getSuccessRedirectTimeout()
        ?? RuntimeProfile::DEFAULT_SUCCESS_REDIRECT_TIMEOUT,
      '#min' => 0,
      '#max' => RuntimeProfile::MAX_SUCCESS_REDIRECT_TIMEOUT,
      '#step' => 1,
      '#required' => TRUE,
    ];
    $settings['logging_enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable Logging'),
      '#description' => $this->t(
        'Only sanitized request and response metadata may be logged.',
      ),
      '#default_value' => $profile?->isLoggingEnabled() ?? FALSE,
    ];
  }

  /**
   * Builds a secret-free explanation of the current live key state.
   *
   * @param bool|null $has_live_keys
   *   Whether both valid live keys are installed, or NULL when unavailable.
   *
   * @return array<string, mixed>
   *   The key status form element.
   */
  private function buildLiveKeyStatus(?bool $has_live_keys): array {
    if ($has_live_keys === TRUE) {
      $title = $this->t('Keys installed');
      $message = $this->t(
        'Both live keys are installed for this payment gateway. Leave both upload fields empty to keep them unchanged. To replace them, upload both files together. Changing the Merchant ID does not replace the keys.',
      );
      $message_type = 'status';
    }
    elseif ($has_live_keys === FALSE) {
      $title = $this->t('Keys not installed');
      $message = $this->t(
        'Live keys are not installed. Upload both key files together before saving live mode. After installation, leave both upload fields empty to keep the keys unchanged. Changing the Merchant ID does not replace the keys.',
      );
      $message_type = 'warning';
    }
    else {
      $title = $this->t('Key status unavailable');
      $message = $this->t(
        'The live key status is unavailable because the local settings could not be read. Existing keys are never displayed. Upload both files together only when installing or replacing the keys.',
      );
      $message_type = 'warning';
    }

    return [
      '#type' => 'item',
      '#title' => $title,
      '#markup' => $message,
      '#wrapper_attributes' => [
        'class' => ['messages', 'messages--' . $message_type],
        'role' => 'status',
      ],
    ];
  }

}
