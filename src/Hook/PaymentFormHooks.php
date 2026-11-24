<?php

declare(strict_types=1);

namespace Drupal\commerce_novapay\Hook;

use Drupal\Core\Entity\EntityFormInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\commerce_payment\Entity\PaymentInterface;
use Drupal\commerce_payment\Entity\PaymentGatewayInterface;

/**
 * Provides NovaPay-specific payment form labels.
 */
final class PaymentFormHooks {

  use StringTranslationTrait;

  /**
   * Constructs the payment form hooks.
   */
  public function __construct(
    private readonly RouteMatchInterface $routeMatch,
    TranslationInterface $string_translation,
  ) {
    $this->stringTranslation = $string_translation;
  }

  /**
   * Implements hook_form_FORM_ID_alter() for commerce_payment_form.
   *
   * @phpstan-param array<array-key, mixed> $form
   */
  #[Hook('form_commerce_payment_form_alter')]
  public function alter(array &$form, FormStateInterface $form_state): void {
    $form_object = $form_state->getFormObject();
    if (!$form_object instanceof EntityFormInterface) {
      return;
    }
    $payment = $form_object->getEntity();
    if (!$payment instanceof PaymentInterface
      || $payment->getPaymentGateway()?->getPluginId() !== 'novapay') {
      return;
    }
    $operation = $this->routeMatch->getRawParameter('operation');
    if (in_array($operation, ['refund', 'void'], TRUE)) {
      $form['actions']['submit']['#value'] = $this->t('Refund via NovaPay', [], ['context' => 'NovaPay refund submission']);
    }
    if ($operation === 'void') {
      $form['actions']['cancel']['#title'] = $this->t('Back', [], ['context' => 'NovaPay hold cancellation']);
    }
  }

  /**
   * Implements hook_form_FORM_ID_alter() for commerce_payment_gateway_form.
   *
   * @phpstan-param array<array-key, mixed> $form
   */
  #[Hook('form_commerce_payment_gateway_form_alter')]
  public function alterGateway(array &$form, FormStateInterface $form_state): void {
    $form_object = $form_state->getFormObject();
    if (!$form_object instanceof EntityFormInterface) {
      return;
    }
    $gateway = $form_object->getEntity();
    if ($gateway instanceof PaymentGatewayInterface && $gateway->getPluginId() === 'novapay') {
      $form['actions']['submit']['#value'] = $this->t('Save changes', [], ['context' => 'NovaPay settings']);
    }
  }

}
