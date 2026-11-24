<?php

declare(strict_types=1);

namespace Drupal\commerce_novapay\Plugin\Commerce\PaymentGateway;

use Drupal\Core\Entity\EntityFormInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\commerce_novapay\Form\RuntimeSettingsFormBuilder;
use Drupal\Core\Url;
use Drupal\commerce_novapay\Credential\CredentialResolverInterface;
use Drupal\commerce_novapay\Credential\NovaPayMode;
use Drupal\commerce_novapay\Credential\RsaKeyValidatorInterface;
use Drupal\commerce_novapay\Exception\InvalidRuntimeProfileException;
use Drupal\commerce_novapay\Phone\CustomerProfilePhoneInspectorInterface;
use Drupal\commerce_novapay\Runtime\CredentialChangeGuardInterface;
use Drupal\commerce_novapay\Runtime\RuntimeConfiguration;
use Drupal\commerce_novapay\Runtime\RuntimeConfigurationProviderInterface;
use Drupal\commerce_novapay\Runtime\RuntimeProfile;
use Drupal\commerce_novapay\Runtime\RuntimeProfileStorageInterface;
use Drupal\commerce_novapay\Runtime\TransactionMode;
use Drupal\commerce_payment\Entity\PaymentGatewayInterface;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\OffsitePaymentGatewayBase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Provides the environment-local NovaPay gateway configuration lifecycle.
 */
abstract class NovaPayConfigurationGatewayBase extends OffsitePaymentGatewayBase implements RuntimeConfigurationProviderInterface {

  private const int MAX_KEY_BYTES = 65536;

  /**
   * The gateway credential-change preflight guard.
   */
  private ?CredentialChangeGuardInterface $credentialChangeGuard = NULL;

  /**
   * {@inheritdoc}
   *
   * @return array<string, mixed>
   *   The default payment gateway configuration.
   */
  public function defaultConfiguration() {
    return [
      'display_logo' => TRUE,
    ] + parent::defaultConfiguration();
  }

  /**
   * The environment-local runtime profile storage.
   */
  private RuntimeProfileStorageInterface $runtimeProfileStorage;

  /**
   * The credential resolver.
   */
  private CredentialResolverInterface $credentialResolver;

  /**
   * The independent RSA key validator.
   */
  private RsaKeyValidatorInterface $keyValidator;

  /**
   * The current request stack used for raw upload fallback.
   */
  private RequestStack $requestStack;

  /**
   * The Commerce customer-profile phone inspector.
   */
  private CustomerProfilePhoneInspectorInterface $customerProfilePhoneInspector;

  /**
   * The environment-local settings form builder.
   */
  private RuntimeSettingsFormBuilder $runtimeSettingsFormBuilder;

  /**
   * {@inheritdoc}
   *
   * @param \Symfony\Component\DependencyInjection\ContainerInterface $container
   *   The service container.
   * @param array<string, mixed> $configuration
   *   The plugin configuration.
   * @param string $plugin_id
   *   The plugin ID.
   * @param array<string, mixed> $plugin_definition
   *   The plugin definition.
   */
  public static function create(
    ContainerInterface $container,
    array $configuration,
    $plugin_id,
    $plugin_definition,
  ) {
    $instance = parent::create(
      $container,
      $configuration,
      $plugin_id,
      $plugin_definition,
    );
    $instance->runtimeProfileStorage = $container->get(
      'commerce_novapay.runtime_profile_storage',
    );
    $instance->credentialChangeGuard = $container->get('commerce_novapay.credential_change_guard');
    $instance->credentialResolver = $container->get(
      'commerce_novapay.credential_resolver',
    );
    $instance->keyValidator = $container->get(
      'commerce_novapay.rsa_key_validator',
    );
    $instance->runtimeSettingsFormBuilder = $container->get(
      'commerce_novapay.runtime_settings_form_builder',
    );
    $instance->requestStack = $container->get('request_stack');
    $instance->customerProfilePhoneInspector = $container->get(
      'commerce_novapay.customer_profile_phone_inspector',
    );

    return $instance;
  }

  /**
   * {@inheritdoc}
   *
   * @param array<array-key, mixed> $form
   *   The plugin configuration form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The complete form state.
   *
   * @return array<string, mixed>
   *   The completed plugin configuration form.
   */
  public function buildConfigurationForm(
    array $form,
    FormStateInterface $form_state,
  ) {
    $form = parent::buildConfigurationForm($form, $form_state);
    $form['display_logo'] = [
      '#type' => 'checkbox',
      '#title' => $this->t(
        'Display the NovaPay logo instead of the payment method name',
      ),
      '#description' => $this->t(
        'When disabled, checkout displays the configured display name.',
      ),
      '#default_value' => $this->configuration['display_logo'] ?? TRUE,
    ];
    $profile = NULL;
    $profile_error = FALSE;
    $has_live_keys = NULL;
    $has_test_keys = FALSE;

    try {
      $gateway_uuid = $this->getGatewayUuid($form_state);
      $profile = $this->runtimeProfileStorage->load($gateway_uuid);
      $has_live_keys = $this->runtimeProfileStorage
        ->hasValidLiveKeys($gateway_uuid);
      $has_test_keys = $this->runtimeProfileStorage->hasValidTestKeys($gateway_uuid);
    }
    catch (\RuntimeException) {
      $profile_error = TRUE;
    }

    $form['runtime_settings'] = $this->runtimeSettingsFormBuilder->build(
      $profile,
      $profile_error,
      $has_live_keys,
      $has_test_keys,
    );

    $form['notify_url'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Postback (callback_url)'),
      '#default_value' => $this->getNotifyUrlValue($form_state),
      '#disabled' => TRUE,
      '#description' => $this->t(
        'Commerce generates this URL. It cannot be entered manually.',
      ),
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   *
   * @param array<array-key, mixed> $form
   *   The plugin configuration form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The complete form state.
   */
  public function validateConfigurationForm(
    array &$form,
    FormStateInterface $form_state,
  ): void {
    parent::validateConfigurationForm($form, $form_state);
    $values = $this->getRuntimeValues($form, $form_state);
    $mode = NovaPayMode::tryFrom((string) ($values['runtime_mode'] ?? ''));
    $transaction_mode = TransactionMode::tryFrom(
      (string) ($values['transaction_mode'] ?? ''),
    );

    if ($mode === NULL) {
      $form_state->setError(
        $form['runtime_settings']['runtime_mode'],
        $this->t('Select a valid NovaPay API mode.'),
      );
    }
    if ($transaction_mode === NULL) {
      $form_state->setError(
        $form['runtime_settings']['transaction_mode'],
        $this->t('Select a valid transaction mode.'),
      );
    }

    $success_redirect_timeout = filter_var(
      $values['success_redirect_timeout'] ?? NULL,
      FILTER_VALIDATE_INT,
    );
    if (
      $success_redirect_timeout === FALSE
      || $success_redirect_timeout < 0
      || $success_redirect_timeout > RuntimeProfile::MAX_SUCCESS_REDIRECT_TIMEOUT
    ) {
      $form_state->setError(
        $form['runtime_settings']['success_redirect_timeout'],
        $this->t('Enter a success redirect delay from 0 to @maximum seconds.', [
          '@maximum' => RuntimeProfile::MAX_SUCCESS_REDIRECT_TIMEOUT,
        ]),
      );
    }

    $this->validateCustomerProfilePhones($form, $form_state);

    $live_values = $values['live_credentials'] ?? [];
    $merchant_id = is_array($live_values)
      ? trim((string) ($live_values['merchant_id'] ?? ''))
      : '';
    if ($mode === NovaPayMode::Live && $merchant_id === '') {
      $form_state->setError(
        $form['runtime_settings']['live_credentials']['merchant_id'],
        $this->t('Merchant ID is required in live mode.'),
      );
    }

    $custom_test = $mode === NovaPayMode::Test && !empty($values['custom_test_credentials']);
    $test_values = $values['custom_test'] ?? [];
    if ($custom_test && (!is_array($test_values) || trim((string) ($test_values['merchant_id'] ?? '')) === '')) {
      $form_state->setError(
        $form['runtime_settings']['custom_test']['merchant_id'],
        $this->t('Test Merchant ID is required when custom test credentials are enabled.'),
      );
    }

    try {
      $gateway_uuid = $this->getGatewayUuid($form_state);
      $this->runtimeProfileStorage->assertWritable($gateway_uuid);
      if ($mode === NovaPayMode::Live) {
        $this->validateKeyUploads($form, $form_state, $gateway_uuid, FALSE);
      }
      elseif ($custom_test) {
        $this->validateKeyUploads($form, $form_state, $gateway_uuid, TRUE);
      }
      if (!$form_state->getErrors() && $this->credentialChangeGuard !== NULL) {
        $prefix = $mode === NovaPayMode::Test ? 'novapay_test_' : 'novapay_';
        $has_upload = ($mode === NovaPayMode::Live || $custom_test)
          && ($this->getUploadedFile($form_state, $prefix . 'private_key_upload') !== NULL
            || $this->getUploadedFile($form_state, $prefix . 'public_key_upload') !== NULL);
        $this->credentialChangeGuard->assertChangeAllowed(
          $gateway_uuid,
          $this->runtimeProfileStorage->load($gateway_uuid),
          $this->buildSubmittedProfile($values, $gateway_uuid),
          $has_upload,
        );
      }
    }
    catch (InvalidRuntimeProfileException $exception) {
      $form_state->setError(
        $form['runtime_settings'],
        ($exception->getMessage() === 'This gateway already has NovaPay sessions. Keep its mode and credentials unchanged and create a separate gateway for different credentials.')
          ? $this->t('This gateway already has NovaPay sessions. Keep its mode and credentials unchanged and create a separate gateway for different credentials.')
          : $this->t('NovaPay local settings cannot be saved securely.'),
      );
    }
  }

  /**
   * Validates phone sources on all Commerce customer profile types.
   *
   * @param array<array-key, mixed> $form
   *   The plugin configuration form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The complete form state.
   */
  private function validateCustomerProfilePhones(
    array &$form,
    FormStateInterface $form_state,
  ): void {
    $readiness = $this->customerProfilePhoneInspector->inspect();
    if ($readiness->isReady()) {
      return;
    }

    $messages = [];
    if ($readiness->getMissingTelephone() !== []) {
      $messages[] = (string) $this->t(
        'Add a Telephone field to these Commerce customer profile types and mark it as the NovaPay payment phone: @types.',
        ['@types' => implode(', ', $readiness->getMissingTelephone())],
      );
    }
    if ($readiness->getUnmarkedTelephone() !== []) {
      $messages[] = (string) $this->t(
        'A Telephone field exists on these Commerce customer profile types. Edit its field settings and select “Use this field as the NovaPay payment phone”: @types.',
        ['@types' => implode(', ', $readiness->getUnmarkedTelephone())],
      );
    }

    $form_state->setError(
      $form['runtime_settings'],
      implode(' ', $messages),
    );
  }

  /**
   * {@inheritdoc}
   *
   * @param array<array-key, mixed> $form
   *   The plugin configuration form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The complete form state.
   */
  public function submitConfigurationForm(
    array &$form,
    FormStateInterface $form_state,
  ): void {
    parent::submitConfigurationForm($form, $form_state);
    if ($form_state->getErrors()) {
      return;
    }

    $configuration_values = $form_state->getValue($form['#parents']);
    if (is_array($configuration_values)) {
      $this->configuration['display_logo'] =
        !empty($configuration_values['display_logo']);
    }

    $values = $this->getRuntimeValues($form, $form_state);
    $mode = NovaPayMode::from((string) $values['runtime_mode']);
    $profile = $this->buildSubmittedProfile($values, $this->getGatewayUuid($form_state));
    $custom_test = $profile->usesCustomTestCredentials();

    $private_key = NULL;
    $public_key = NULL;
    if ($mode === NovaPayMode::Live || $custom_test) {
      $prefix = $mode === NovaPayMode::Test ? 'novapay_test_' : 'novapay_';
      $private_key = $this->readUploadedPem(
        $this->getUploadedFile($form_state, $prefix . 'private_key_upload'),
      );
      $public_key = $this->readUploadedPem(
        $this->getUploadedFile($form_state, $prefix . 'public_key_upload'),
      );
    }

    try {
      $this->runtimeProfileStorage->save(
        $this->getGatewayUuid($form_state),
        $profile,
        $private_key,
        $public_key,
      );
    }
    catch (InvalidRuntimeProfileException) {
      // Abort the remaining Commerce entity save handlers after a save race.
      throw new HttpException(409, 'NovaPay settings changed or could not be saved securely. Reload the gateway form and try again. Use a separate gateway for different credentials when NovaPay sessions already exist.');
    }
  }

  /**
   * Builds a profile while retaining inactive environment credentials.
   *
   * @param array<string, mixed> $values
   *   Submitted runtime values.
   * @param string $gateway_uuid
   *   The gateway UUID.
   *
   * @return \Drupal\commerce_novapay\Runtime\RuntimeProfile
   *   The profile ready for validation or persistence.
   */
  private function buildSubmittedProfile(array $values, string $gateway_uuid): RuntimeProfile {
    $mode = NovaPayMode::from((string) $values['runtime_mode']);
    $live_values = $values['live_credentials'] ?? [];
    $merchant_id = is_array($live_values)
      ? trim((string) ($live_values['merchant_id'] ?? ''))
      : '';
    $existing = $this->runtimeProfileStorage->load($gateway_uuid);
    $custom_test = $mode === NovaPayMode::Test
      ? !empty($values['custom_test_credentials'])
      : ($existing?->usesCustomTestCredentials() ?? FALSE);
    $test_values = $values['custom_test'] ?? [];
    $test_merchant_id = $mode === NovaPayMode::Test && $custom_test && is_array($test_values)
      ? trim((string) ($test_values['merchant_id'] ?? ''))
      : $existing?->getTestMerchantId();
    return new RuntimeProfile(
      $mode,
      $mode === NovaPayMode::Live ? $merchant_id : $existing?->getLiveMerchantId(),
      TransactionMode::from((string) $values['transaction_mode']),
      trim((string) ($values['recipient_identifier'] ?? '')),
      !empty($values['logging_enabled']),
      (int) $values['success_redirect_timeout'],
      $custom_test,
      $test_merchant_id,
    );
  }

  /**
   * Resolves settings, credentials, and the matching API endpoint.
   */
  public function getRuntimeConfiguration(): RuntimeConfiguration {
    return $this->credentialResolver->resolveRuntimeConfiguration(
      $this->getGatewayUuid(),
    );
  }

  /**
   * Validates optional environment-specific rotation uploads.
   *
   * @param array<array-key, mixed> $form
   *   The plugin configuration form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The complete form state.
   * @param string $gateway_uuid
   *   The Commerce payment gateway UUID.
   * @param bool $test
   *   Whether to validate custom sandbox rather than live keys.
   */
  private function validateKeyUploads(
    array &$form,
    FormStateInterface $form_state,
    string $gateway_uuid,
    bool $test,
  ): void {
    $prefix = $test ? 'novapay_test_' : 'novapay_';
    $container = $test ? 'custom_test' : 'live_credentials';
    $private_upload = $this->getUploadedFile(
      $form_state,
      $prefix . 'private_key_upload',
    );
    $public_upload = $this->getUploadedFile(
      $form_state,
      $prefix . 'public_key_upload',
    );

    if (($private_upload === NULL) !== ($public_upload === NULL)) {
      $message = $test
        ? $this->t('Upload both test key files together.')
        : $this->t('Upload both live key files together.');
      $form_state->setError(
        $form['runtime_settings'][$container]['keys']['private_key_upload'],
        $message,
      );
      $form_state->setError(
        $form['runtime_settings'][$container]['keys']['public_key_upload'],
        $message,
      );
      return;
    }

    if ($private_upload === NULL && $public_upload === NULL) {
      $has_keys = $test
        ? $this->runtimeProfileStorage->hasValidTestKeys($gateway_uuid)
        : $this->runtimeProfileStorage->hasValidLiveKeys($gateway_uuid);
      if (!$has_keys) {
        $form_state->setError(
          $form['runtime_settings'][$container]['keys']['private_key_upload'],
          $test ? $this->t('Upload both test key files.') : $this->t('Upload both live key files.'),
        );
      }
      return;
    }

    try {
      $private_key = $this->readUploadedPem($private_upload);
      $public_key = $this->readUploadedPem($public_upload);
      if ($private_key === NULL || $public_key === NULL) {
        throw InvalidRuntimeProfileException::incompleteKeyUpload();
      }
      $this->keyValidator->validatePrivateKey($private_key);
      $this->keyValidator->validatePublicKey($public_key);
    }
    catch (\RuntimeException) {
      $form_state->setError(
        $form['runtime_settings'][$container]['keys'],
        $this->t('Upload valid RSA private and public PEM files.'),
      );
    }
  }

  /**
   * Gets the submitted values inside the runtime settings container.
   *
   * @param array<array-key, mixed> $form
   *   The plugin configuration form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The complete form state.
   *
   * @return array<string, mixed>
   *   Submitted runtime values.
   */
  private function getRuntimeValues(
    array $form,
    FormStateInterface $form_state,
  ): array {
    $parents = $form['#parents'] ?? [];
    $values = $form_state->getValue($parents);
    if (!is_array($values)) {
      return [];
    }

    $runtime_values = $values['runtime_settings'] ?? NULL;
    return is_array($runtime_values) ? $runtime_values : [];
  }

  /**
   * Gets the first uploaded file from a Form API file element.
   *
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The complete form state.
   * @param string $key
   *   The top-level upload element key.
   */
  private function getUploadedFile(
    FormStateInterface $form_state,
    string $key,
  ): ?UploadedFile {
    $uploads = $form_state->getValue($key);
    if ($uploads instanceof UploadedFile) {
      return $uploads;
    }
    if (is_array($uploads)) {
      foreach ($uploads as $upload) {
        if ($upload instanceof UploadedFile) {
          return $upload;
        }
      }
    }

    // Drupal Core's single-file value callback casts the Symfony
    // UploadedFile object to an array in some supported Core/Symfony
    // combinations. Read the exact allow-listed field from the request as a
    // compatibility fallback; size and upload validity are checked separately.
    $request = $this->requestStack->getCurrentRequest();
    $request_uploads = $request?->files->get('files', []);
    if (!is_array($request_uploads)) {
      return NULL;
    }

    $upload = $request_uploads[$key] ?? NULL;
    if ($upload instanceof UploadedFile) {
      return $upload;
    }
    if (is_array($upload)) {
      foreach ($upload as $candidate) {
        if ($candidate instanceof UploadedFile) {
          return $candidate;
        }
      }
    }

    return NULL;
  }

  /**
   * Reads a bounded valid HTTP upload without persisting it.
   */
  private function readUploadedPem(
    ?UploadedFile $upload,
  ): ?string {
    if ($upload === NULL) {
      return NULL;
    }

    $size = $upload->getSize();
    if (
      !$upload->isValid()
      || !is_int($size)
      || $size < 1
      || $size > self::MAX_KEY_BYTES
    ) {
      throw InvalidRuntimeProfileException::incompleteKeyUpload();
    }

    $contents = @file_get_contents($upload->getPathname());
    if (!is_string($contents) || strlen($contents) !== $size) {
      throw InvalidRuntimeProfileException::incompleteKeyUpload();
    }

    return $contents;
  }

  /**
   * Gets the current Commerce payment gateway UUID.
   */
  private function getGatewayUuid(
    ?FormStateInterface $form_state = NULL,
  ): string {
    // The Commerce plugin-configuration inline form creates a standalone
    // plugin instance, so it has no parent entity while the gateway admin
    // form is being built or submitted. Resolve that entity from the form
    // object for admin operations.
    $form_gateway = $this->getFormGateway($form_state);
    if ($form_gateway !== NULL) {
      $uuid = $form_gateway->uuid();
      if (is_string($uuid) && $uuid !== '') {
        return $uuid;
      }
    }

    // Commerce documents this property as non-null, but its own base class
    // leaves it unavailable when a plugin is configured without a parent.
    // @phpstan-ignore-next-line isset.property
    if (!isset($this->parentEntity)) {
      throw InvalidRuntimeProfileException::invalidProfile();
    }

    $uuid = $this->parentEntity->uuid();
    if (!is_string($uuid) || $uuid === '') {
      throw InvalidRuntimeProfileException::invalidProfile();
    }

    return $uuid;
  }

  /**
   * Gets a safe read-only callback URL value.
   */
  private function getNotifyUrlValue(
    FormStateInterface $form_state,
  ): string {
    $form_gateway = $this->getFormGateway($form_state);
    if ($form_gateway !== NULL && $form_gateway->id()) {
      return Url::fromRoute(
        'commerce_payment.notify',
        ['commerce_payment_gateway' => $form_gateway->id()],
        ['absolute' => TRUE],
      )->toString();
    }

    // @phpstan-ignore-next-line isset.property
    if (!isset($this->parentEntity) || !$this->parentEntity->id()) {
      return (string) $this->t('Available after the gateway is saved.');
    }

    return $this->getNotifyUrl()->toString();
  }

  /**
   * Gets the gateway entity from an administrative entity form.
   */
  private function getFormGateway(
    ?FormStateInterface $form_state,
  ): ?PaymentGatewayInterface {
    $form_object = $form_state?->getFormObject();
    if (!$form_object instanceof EntityFormInterface) {
      return NULL;
    }

    $entity = $form_object->getEntity();
    return $entity instanceof PaymentGatewayInterface ? $entity : NULL;
  }

}
