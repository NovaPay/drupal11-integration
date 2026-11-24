<?php

declare(strict_types=1);

namespace Drupal\commerce_novapay\PluginForm;

use Drupal\Component\Utility\Html;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\Element\Number;
use Drupal\commerce_novapay\Payment\SupportsItemRefundsInterface;
use Drupal\commerce_price\Calculator;
/*
 * use Drupal\commerce_novapay\Payment\RefundAmount;
 */
use Drupal\commerce_payment\Exception\InvalidRequestException;
use Drupal\commerce_payment\Entity\PaymentInterface;
use Drupal\commerce_payment\PluginForm\PaymentGatewayFormBase;

/**
 * Provides exact item quantities for partial or empty-selection full refunds.
 */
final class NovaPayRefundForm extends PaymentGatewayFormBase {

  /**
   * {@inheritdoc}
   *
   * @param array<array-key, mixed> $form
   *   The refund operation form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The complete form state.
   *
   * @return array<array-key, mixed>
   *   The item-level refund form.
   */
  public function buildConfigurationForm(
    array $form,
    FormStateInterface $form_state,
  ): array {
    $payment = $this->getPayment();
    $plugin = $this->getRefundPlugin();
    $items = $plugin->getAllRefundItems($payment);
    $currency_code = $payment->getBalance()->getCurrencyCode();
    try {
      $currency = \Drupal::service('commerce_price.currency_repository')->get($currency_code)->getSymbol();
    }
    catch (\Throwable) {
      $currency = $currency_code;
    }
    $formatter = \Drupal::service('commerce_price.currency_formatter');

    $form['#success_message'] = $this->t(
      'Refund submitted to NovaPay. Payment and item totals will update after NovaPay confirmation.',
    );
    $form['notice'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['commerce-novapay-refund-notice'], 'role' => 'note'],
      'title' => [
        '#type' => 'html_tag',
        '#tag' => 'h3',
        '#value' => $this->t('Important refund information'),
        '#attributes' => ['class' => ['commerce-novapay-refund-notice__title']],
      ],
      'message' => [
        '#markup' => $this->t('A partial refund is possible when the order payment was credited to the NovaPay account.'),
      ],
    ];
    $form['refund_mode'] = ['#type' => 'value', '#value' => 'items'];
    $form['balance'] = [
      '#type' => 'item',
      '#title' => $this->t('Available refund balance'),
      '#plain_text' => $formatter->format($payment->getBalance()->getNumber(), $currency_code),
    ];
    // phpcs:disable Drupal.Files.LineLength
    /*
     * $form['refund_amount'] = [
     *   '#type' => 'number',
     *   '#element_validate' => [[self::class, 'validateActiveAmount']],
     *   '#min' => '0.01',
     *   '#max' => $payment->getBalance()->getNumber(),
     *   '#step' => '0.01',
     *   '#title' => $this->t('Amount to refund (UAH)'),
     *   '#description' => $this->t(
     *     'Enter a positive amount up to the available balance, with at most two decimal places. Item quantities will stay unchanged.',
     *   ),
     *   '#attributes' => [
     *     'class' => ['commerce-novapay-refund-amount'],
     *     'inputmode' => 'decimal',
     *     'data-currency' => $currency,
     *   ],
     *   '#states' => [
     *     'visible' => [$selector => ['value' => 'amount']],
     *     'required' => [$selector => ['value' => 'amount']],
     *   ],
     * ];
     */
    // phpcs:enable Drupal.Files.LineLength
    $form['items'] = [
      '#type' => 'table',
      '#header' => [
        $this->t('Order item'),
        $this->t('Paid quantity'),
        $this->t('Available'),
        $this->t('Unit price'),
        $this->t('Quantity to refund'),
        $this->t('Amount to refund', [], ['context' => 'NovaPay refund item']),
      ],
      '#tree' => TRUE,
      '#access' => $items !== [],
      '#attributes' => [
        'class' => ['commerce-novapay-refund-items', 'js-form-wrapper'],
        'data-refund-balance' => $payment->getBalance()->getNumber(),
        'data-currency' => $currency,
      ],
    ];
    foreach ($items as $item) {
      $id = $item->getOrderItemId();
      $form['items'][$id] = [];
      $form['items'][$id]['title'] = [
        '#plain_text' => $item->getTitle(),
      ];
      $form['items'][$id]['ordered'] = [
        '#plain_text' => $item->getOrderedQuantity(),
      ];
      $form['items'][$id]['available'] = [
        '#plain_text' => $item->getAvailableQuantity(),
      ];
      $form['items'][$id]['unit_price'] = [
        '#plain_text' => $formatter->format($item->getUnitPrice()->getNumber(), $currency_code),
      ];
      if (Calculator::compare($item->getAvailableQuantity(), '0') <= 0) {
        $form['items'][$id]['quantity'] = [
          '#plain_text' => '-',
        ];
        $form['items'][$id]['amount'] = [
          '#plain_text' => '-',
        ];
      }
      else {
        $form['items'][$id]['quantity'] = [
          '#type' => 'number',
          '#element_validate' => [[self::class, 'validateActiveQuantity']],
          '#title' => $this->t(
            'Quantity to refund for @item',
            ['@item' => $item->getTitle()],
          ),
          '#title_display' => 'invisible',
          '#default_value' => '',
          '#min' => 0,
          '#max' => $item->getAvailableQuantity(),
          '#step' => $item->getQuantityStep(),
          '#attributes' => [
            'class' => ['commerce-novapay-refund-quantity'],
            'data-unit-price' => $item->getUnitPrice()->getNumber(),
            'data-currency' => $currency,
          ],
        ];

        $form['items'][$id]['amount'] = [
          '#type' => 'number',
          '#title' => $this->t('Amount to refund for @item', ['@item' => $item->getTitle()]),
          '#title_display' => 'invisible',
          '#default_value' => '',
          '#min' => 0,
          '#max' => $payment->getBalance()->getNumber(),
          '#step' => '0.01',
          '#attributes' => [
            'class' => ['commerce-novapay-refund-line-total'],
          ],
          '#field_suffix' => Html::escape($currency),
          '#wrapper_attributes' => ['class' => ['commerce-novapay-refund-amount-inline']],
        ];
      }
    }
    $form['total'] = [
      '#type' => 'item',
      '#title' => $this->t('Total refund amount'),
      'value' => [
        '#type' => 'html_tag',
        '#tag' => 'span',
        '#value' => Html::escape($formatter->format($payment->getBalance()->getNumber(), $currency_code)),
        '#attributes' => ['class' => ['commerce-novapay-refund-total']],
      ],
    ];
    $form['empty_selection'] = [
      '#type' => 'container',
      'help' => ['#markup' => $this->t(
        'Leave all quantities and amounts empty or zero for a full refund of the remaining paid balance. Otherwise enter quantities or compensation amounts within that balance.',
      )],
    ];
    if ($items === []) {
      unset($form['items']);
      $form['empty_selection']['help']['#markup'] = $this->t('Only a full refund of the remaining paid balance is available for this payment.');
    }
    $form['#attached']['library'][] = 'commerce_novapay/refund_form';

    return $form;
  }

  /**
   * {@inheritdoc}
   *
   * @param array<array-key, mixed> $form
   *   The refund operation form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The complete form state.
   */
  public function validateConfigurationForm(
    array &$form,
    FormStateInterface $form_state,
  ): void {
    $values = $form_state->getValue($form['#parents']);
    $mode = is_array($values) ? ($values['refund_mode'] ?? NULL) : NULL;
    // Restore the items/amount allowlist when re-enabling the blocks below.
    if ($mode !== 'items') {
      $form_state->setError($form['refund_mode'], $this->t('Select a valid refund mode.'));
      return;
    }
    // phpcs:disable Drupal.Files.LineLength
    /*
     * if ($mode === 'amount') {
     *   try {
     *     $amount = RefundAmount::parse($values['refund_amount'] ?? NULL);
     *     if ($amount->greaterThan($this->getPayment()->getBalance())) {
     *       throw new InvalidRequestException('The refund amount exceeds the paid balance.');
     *     }
     *   }
     *   catch (InvalidRequestException $exception) {
     *     $form_state->setError($form['refund_amount'], $this->t('Enter a positive UAH amount within the available balance, with at most two decimal places.'));
     *   }
     *   return;
     * }
     */
    // phpcs:enable Drupal.Files.LineLength
    $items_element = $form['items'] ?? $form['balance'];

    try {
      $quantities = $this->getSubmittedQuantities($form, $form_state);
    }
    catch (InvalidRequestException $exception) {
      $form_state->setError($items_element, $this->t('Invalid refund item quantities.'));
      return;
    }
    try {
      $amounts = $this->getSubmittedAmounts($form, $form_state);
      $builder = \Drupal::service('commerce_novapay.refund_selection_builder');
      if ($amounts !== []) {
        $builder->buildAmountSelection($this->getPayment(), $quantities, $amounts);
      }
      else {
        [$items] = $builder->buildPartialSelection($this->getPayment(), $quantities);
        if ($items !== []) {
          $builder->getSelectionTotal($items, $this->getPayment());
        }
      }
    }
    catch (InvalidRequestException $exception) {
      $form_state->setError($items_element, $exception->getMessage());
    }
  }

  /**
   * {@inheritdoc}
   *
   * @param array<array-key, mixed> $form
   *   The refund operation form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The complete form state.
   */
  public function submitConfigurationForm(
    array &$form,
    FormStateInterface $form_state,
  ): void {
    $values = $form_state->getValue($form['#parents']);
    $mode = is_array($values) ? ($values['refund_mode'] ?? NULL) : NULL;
    // phpcs:disable Drupal.Files.LineLength
    /*
     * if ($mode === 'amount') {
     *   $this->getRefundPlugin()->refundAmount(
     *     $this->getPayment(),
     *     RefundAmount::parse($values['refund_amount'] ?? NULL),
     *   );
     *   return;
     * }
     */
    // phpcs:enable Drupal.Files.LineLength
    if ($mode !== 'items') {
      throw new InvalidRequestException('Select a valid refund mode.');
    }
    $this->getRefundPlugin()->refundItems(
      $this->getPayment(),
      $this->getSubmittedQuantities($form, $form_state),
      $this->getSubmittedAmounts($form, $form_state),
    );
  }

  /**
   * Runs number validation only for the active monetary mode.
   *
   * @param array<array-key, mixed> $element
   *   The monetary element.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The complete form state.
   * @param array<array-key, mixed> $complete_form
   *   The complete form.
   */
  public static function validateActiveAmount(
    array &$element,
    FormStateInterface $form_state,
    array &$complete_form,
  ): void {
    $parents = array_slice($element['#parents'], 0, -1);
    $parents[] = 'refund_mode';
    if ($form_state->getValue($parents) !== 'amount') {
      return;
    }
    Number::validateNumber($element, $form_state, $complete_form);
  }

  /**
   * Runs number validation only for the active item mode.
   *
   * @param array<array-key, mixed> $element
   *   The quantity element.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The complete form state.
   * @param array<array-key, mixed> $complete_form
   *   The complete form.
   */
  public static function validateActiveQuantity(
    array &$element,
    FormStateInterface $form_state,
    array &$complete_form,
  ): void {
    $parents = array_slice($element['#parents'], 0, -3);
    $parents[] = 'refund_mode';
    if ($form_state->getValue($parents) === 'amount') {
      return;
    }
    Number::validateNumber($element, $form_state, $complete_form);
  }

  /**
   * Gets the payment bound to this operation form.
   */
  private function getPayment(): PaymentInterface {
    if (!$this->entity instanceof PaymentInterface) {
      throw new \LogicException('The NovaPay refund form requires a payment.');
    }

    return $this->entity;
  }

  /**
   * Gets the item-refund-capable gateway plugin.
   */
  private function getRefundPlugin(): SupportsItemRefundsInterface {
    if (!$this->plugin instanceof SupportsItemRefundsInterface) {
      throw new \LogicException(
        'The NovaPay gateway does not support item refunds.',
      );
    }

    return $this->plugin;
  }

  /**
   * Extracts explicit amounts and calculates amounts for blank quantity rows.
   *
   * @param array<array-key, mixed> $form
   *   The refund form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The submitted form state.
   *
   * @return array<int, string>
   *   Explicit amounts keyed by item ID.
   */
  private function getSubmittedAmounts(array $form, FormStateInterface $form_state): array {
    $this->getSubmittedQuantities($form, $form_state);
    $values = $form_state->getValue($form['#parents']);
    $amounts = [];
    foreach ($values['items'] ?? [] as $id => $row) {
      if (!is_array($row)) {
        throw new InvalidRequestException('Invalid refund item amount.');
      }
      if (isset($row['amount']) && !is_string($row['amount'])) {
        throw new InvalidRequestException('Invalid refund item amount.');
      }
      $amount = trim($row['amount'] ?? '');
      if ($amount !== '') {
        $amounts[(int) $id] = $amount;
      }
    }
    $builder = \Drupal::service('commerce_novapay.refund_selection_builder');
    [$selections] = $builder->buildPartialSelection($this->getPayment(), $this->getSubmittedQuantities($form, $form_state));
    foreach ($selections as $selection) {
      $id = (int) $selection->getOrderItemId();
      if (!isset($amounts[$id])) {
        $amounts[$id] = $selection->getAmount()->getNumber();
      }
    }
    return $amounts;
  }

  /**
   * Extracts bounded quantity strings from the nested plugin form.
   *
   * @param array<array-key, mixed> $form
   *   The refund operation form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The complete form state.
   *
   * @return array<int, string>
   *   Submitted quantities keyed by order item ID.
   */
  private function getSubmittedQuantities(
    array $form,
    FormStateInterface $form_state,
  ): array {
    $values = $form_state->getValue($form['#parents']);
    if (!is_array($values) || (array_key_exists('items', $values) && !is_array($values['items']))) {
      throw new InvalidRequestException('Invalid refund item quantities.');
    }
    $rows = $values['items'] ?? [];
    $quantities = [];
    foreach ($rows as $id => $row) {
      if (!is_array($row)
        || preg_match('/^[1-9][0-9]*$/D', (string) $id) !== 1
        || (array_key_exists('quantity', $row) && !is_string($row['quantity']))) {
        throw new InvalidRequestException('Invalid refund item quantities.');
      }
      if (array_key_exists('quantity', $row)) {
        $quantities[(int) $id] = $row['quantity'];
      }
    }

    return $quantities;
  }

}
