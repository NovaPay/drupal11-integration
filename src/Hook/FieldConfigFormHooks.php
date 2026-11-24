<?php

declare(strict_types=1);

namespace Drupal\commerce_novapay\Hook;

use Drupal\Core\Entity\EntityFormInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\field\FieldConfigInterface;

/**
 * Alters telephone field configuration for NovaPay phone selection.
 */
final class FieldConfigFormHooks {

  use StringTranslationTrait;

  /**
   * Constructs the field configuration form hooks.
   */
  public function __construct(TranslationInterface $string_translation) {
    $this->stringTranslation = $string_translation;
  }

  /**
   * Implements hook_form_FORM_ID_alter() for field_config_edit_form.
   *
   * @phpstan-param array<array-key, mixed> $form
   */
  #[Hook('form_field_config_edit_form_alter')]
  public function alter(
    array &$form,
    FormStateInterface $form_state,
  ): void {
    $form_object = $form_state->getFormObject();
    if (!$form_object instanceof EntityFormInterface) {
      return;
    }
    $field = $form_object->getEntity();
    if (
      !$field instanceof FieldConfigInterface
      || $field->getType() !== 'telephone'
      || !in_array(
        $field->getTargetEntityTypeId(),
        ['commerce_order', 'profile', 'user'],
        TRUE,
      )
    ) {
      return;
    }

    $form['commerce_novapay_payment_phone'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Use this field as the NovaPay payment phone'),
      '#description' => $this->t(
        'NovaPay will prefill its checkout phone from this field when the value is available and valid.',
      ),
      '#default_value' => $field->getThirdPartySetting(
        'commerce_novapay',
        'payment_phone',
        FALSE,
      ),
      '#weight' => 20,
    ];
    $form['actions']['submit']['#submit'][] =
      [self::class, 'submit'];
  }

  /**
   * Saves the payment-phone designation on a telephone field.
   *
   * @param array<array-key, mixed> $form
   *   The telephone field configuration form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public static function submit(
    array $form,
    FormStateInterface $form_state,
  ): void {
    $form_object = $form_state->getFormObject();
    if (!$form_object instanceof EntityFormInterface) {
      return;
    }
    $field = $form_object->getEntity();
    if (!$field instanceof FieldConfigInterface) {
      return;
    }

    if ($form_state->getValue('commerce_novapay_payment_phone')) {
      $field->setThirdPartySetting(
        'commerce_novapay',
        'payment_phone',
        TRUE,
      );
    }
    else {
      $field->unsetThirdPartySetting('commerce_novapay', 'payment_phone');
    }
    $field->save();
  }

}
