<?php

declare(strict_types=1);

namespace Drupal\commerce_novapay\Payment;

use CommerceGuys\Intl\Formatter\CurrencyFormatterInterface;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\commerce_order\Entity\OrderItemInterface;

/**
 * Builds the virtual confirmed refund amount shared by displays and Views.
 */
final class OrderItemRefundAmountDisplay {

  public function __construct(
    private readonly RefundLedgerRepositoryInterface $ledger,
    private readonly CurrencyFormatterInterface $currencyFormatter,
  ) {}

  /**
   * Builds a formatted positive amount, or literal zero for an unrefunded item.
   *
   * @return array<string, mixed>
   *   The access-controlled amount with cache metadata.
   */
  public function build(OrderItemInterface $item): array {
    $access = $item->access('view', NULL, TRUE);
    $cache = CacheableMetadata::createFromObject($item)
      ->addCacheableDependency($access)
      ->addCacheContexts(['languages:language_interface'])
      // The ledger can change independently of the order item entity.
      ->setCacheMaxAge(0);
    $build = [];
    if ($access->isAllowed() && !$item->isNew()) {
      $build['#plain_text'] = '0';
      $amount = $this->ledger->getRefundedAmountForOrderItem((int) $item->id());
      if ($amount !== NULL && $amount->isPositive()) {
        $build['#plain_text'] = $this->currencyFormatter->format(
          $amount->getNumber(),
          $amount->getCurrencyCode(),
        );
      }
    }
    $cache->applyTo($build);
    return $build;
  }

}
