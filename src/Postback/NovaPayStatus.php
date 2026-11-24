<?php

declare(strict_types=1);

namespace Drupal\commerce_novapay\Postback;

/**
 * Lists documented NovaPay acquiring session statuses.
 */
enum NovaPayStatus: string {

  case Precreated = 'precreated';
  case Preprocessing = 'preprocessing';
  case AsyncPreprocessing = 'async_preprocessing';
  case Created = 'created';
  case Expired = 'expired';
  case Processing = 'processing';
  case Holded = 'holded';
  case HoldConfirmed = 'hold_confirmed';
  case ProcessingHoldCompletion = 'processing_hold_completion';
  case Paid = 'paid';
  case Failed = 'failed';
  case ProcessingVoid = 'processing_void';
  case Voided = 'voided';

}
