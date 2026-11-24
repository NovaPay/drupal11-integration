<?php

declare(strict_types=1);

namespace Drupal\commerce_novapay\EventSubscriber;

use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\commerce_order\Event\OrderEvents;
use Drupal\commerce_order\Event\OrderSummaryBuildTotalsEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Translates NovaPay adjustment labels in the current interface language.
 */
final class AdjustmentLabelSubscriber implements EventSubscriberInterface {

  use StringTranslationTrait;

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [OrderEvents::ORDER_SUMMARY_BUILD_TOTALS => 'translateLabels'];
  }

  /**
   * Localizes display labels without modifying persisted adjustments or money.
   */
  public function translateLabels(OrderSummaryBuildTotalsEvent $event): void {
    $totals = $event->getTotals();
    foreach ($totals['adjustments'] as &$adjustment) {
      $label = match ($adjustment['type']) {
        'novapay_partial_refund' => $this->t('NovaPay partial refund adjustment'),
        'novapay_partial_capture' => $this->t('NovaPay partial capture adjustment'),
        default => NULL,
      };
      if ($label !== NULL) {
        $adjustment['label'] = $label;
      }
    }
    unset($adjustment);
    $event->setTotals($totals);
  }

}
