<?php

declare(strict_types=1);

namespace Drupal\commerce_novapay\Plugin\views\field;

use Drupal\Component\Render\MarkupInterface;
use Drupal\commerce_novapay\Payment\OrderItemRefundAmountDisplay;
use Drupal\commerce_order\Entity\OrderItemInterface;
use Drupal\views\Attribute\ViewsField;
use Drupal\views\Plugin\views\field\FieldPluginBase;
use Drupal\views\ResultRow;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Displays confirmed item refund totals without altering the item title.
 */
#[ViewsField('commerce_novapay_order_item_refunded_amount')]
final class OrderItemRefundedAmount extends FieldPluginBase {

  /**
   * The shared virtual amount display builder.
   */
  private OrderItemRefundAmountDisplay $amountDisplay;

  /**
   * {@inheritdoc}
   *
   * @param \Symfony\Component\DependencyInjection\ContainerInterface $container
   *   The service container.
   * @param array<string, mixed> $configuration
   *   The plugin configuration.
   * @param string $plugin_id
   *   The plugin ID.
   * @param mixed $plugin_definition
   *   The plugin definition.
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $instance = new static($configuration, $plugin_id, $plugin_definition);
    $instance->amountDisplay = $container->get(OrderItemRefundAmountDisplay::class);
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function query(): void {
    // Use the loaded order item, without joining or multiplying ledger rows.
  }

  /**
   * {@inheritdoc}
   *
   * @return \Drupal\Component\Render\MarkupInterface|string
   *   The formatted positive amount or literal zero with cache metadata.
   */
  public function render(ResultRow $values): MarkupInterface|string {
    $item = $this->getEntity($values);
    if (!$item instanceof OrderItemInterface) {
      return '';
    }
    $build = $this->amountDisplay->build($item);
    return $this->getRenderer()->render($build);
  }

}
