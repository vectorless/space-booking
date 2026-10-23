<?php

declare(strict_types=1);

namespace Drupal\space_booking\Plugin\Validation\Constraint;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Validation\Attribute\Constraint;
use Symfony\Component\Validator\Constraint as SymfonyConstraint;

/**
 * Caps how much one person may book in a week.
 */
#[Constraint(
  id: 'BookingQuota',
  label: new TranslatableMarkup('Booking quota', [], ['context' => 'Validation']),
  type: ['entity'],
)]
final class BookingQuotaConstraint extends SymfonyConstraint {

  /**
   * Shown when the holder's weekly allowance is exhausted.
   */
  public string $message = 'This would take @name over the weekly allowance of @quota. @remaining remains.';

  /**
   * {@inheritdoc}
   */
  public function validatedBy(): string {
    return BookingQuotaValidator::class;
  }

}
