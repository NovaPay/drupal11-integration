<?php

declare(strict_types=1);

namespace Drupal\commerce_novapay\Hook;

use Drupal\Core\Entity\Display\EntityViewDisplayInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\commerce_novapay\Payment\OrderItemRefundAmountDisplay;
use Drupal\commerce_order\Entity\OrderItemInterface;

/**
 * Exposes confirmed item refunds as a configurable virtual display field.
 */
final class OrderItemRefundDisplayHooks {

  use StringTranslationTrait;

  public function __construct(
    private readonly EntityTypeBundleInfoInterface $bundleInfo,
    private readonly OrderItemRefundAmountDisplay $amountDisplay,
  ) {}

  /**
   * Implements hook_entity_extra_field_info().
   *
   * @return array<string, mixed>
   *   Virtual display fields for every order item bundle.
   */
  #[Hook('entity_extra_field_info')]
  public function extraFields(): array {
    $fields = [];
    foreach ($this->bundleInfo->getBundleInfo('commerce_order_item') as $bundle => $info) {
      $fields['commerce_order_item'][$bundle]['display']['novapay_refunded_amount'] = [
        'label' => $this->t('Refunded amount', [], ['context' => 'NovaPay order item']),
        'description' => $this->t('Confirmed NovaPay refunds allocated to this order item.'),
        'weight' => 20,
        'visible' => FALSE,
      ];
    }
    return $fields;
  }

  /**
   * Implements hook_ENTITY_TYPE_view().
   *
   * @param array<string, mixed> $build
   *   The order item render array.
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The order item being rendered.
   * @param \Drupal\Core\Entity\Display\EntityViewDisplayInterface $display
   *   The configured display.
   * @param string $view_mode
   *   The requested view mode.
   */
  #[Hook('commerce_order_item_view')]
  public function view(array &$build, EntityInterface $entity, EntityViewDisplayInterface $display, string $view_mode): void {
    if ($entity instanceof OrderItemInterface && $display->getComponent('novapay_refunded_amount') !== NULL) {
      $build['novapay_refunded_amount'] = $this->amountDisplay->build($entity);
    }
  }

  /**
   * Implements hook_views_data_alter().
   *
   * @param array<string, mixed> $data
   *   Views data definitions.
   */
  #[Hook('views_data_alter')]
  public function viewsData(array &$data): void {
    $data['commerce_order_item']['novapay_refunded_amount'] = [
      'title' => $this->t('Refunded amount', [], ['context' => 'NovaPay order item']),
      'help' => $this->t('Confirmed NovaPay refunds allocated to this order item.'),
      'field' => ['id' => 'commerce_novapay_order_item_refunded_amount'],
    ];
  }

}
