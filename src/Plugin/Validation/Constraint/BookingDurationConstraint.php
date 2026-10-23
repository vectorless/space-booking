<?php

declare(strict_types=1);

namespace Drupal\space_booking\Plugin\Validation\Constraint;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Validation\Attribute\Constraint;
use Symfony\Component\Validator\Constraint as SymfonyConstraint;

/**
 * Enforces the configured minimum and maximum booking length.
 */
#[Constraint(
  id: 'BookingDuration',
  label: new TranslatableMarkup('Booking duration', [], ['context' => 'Validation']),
  type: ['entity'],
)]
final class BookingDurationConstraint extends SymfonyConstraint {

  /**
   * Shown when the booking is shorter than the minimum.
   */
  public string $tooShortMessage = 'Bookings must be at least @min long.';

  /**
   * Shown when the booking is longer than the maximum.
   */
  public string $tooLongMessage = 'Bookings may be at most @max long.';

  /**
   * Shown when the booking does not sit on the slot grid.
   */
  public string $granularityMessage = 'Bookings must start and end on @minute-minute boundaries.';

  /**
   * {@inheritdoc}
   */
  public function validatedBy(): string {
    return BookingDurationValidator::class;
  }

}
