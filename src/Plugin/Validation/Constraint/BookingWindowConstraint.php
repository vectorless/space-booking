<?php

declare(strict_types=1);

namespace Drupal\space_booking\Plugin\Validation\Constraint;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Validation\Attribute\Constraint;
use Symfony\Component\Validator\Constraint as SymfonyConstraint;

/**
 * Constrains how far ahead a booking may or must be made.
 */
#[Constraint(
  id: 'BookingWindow',
  label: new TranslatableMarkup('Booking window', [], ['context' => 'Validation']),
  type: ['entity'],
)]
final class BookingWindowConstraint extends SymfonyConstraint {

  /**
   * Shown when the booking starts in the past.
   */
  public string $pastMessage = 'Bookings cannot be made in the past.';

  /**
   * Shown when the booking is too close to its start time.
   */
  public string $tooSoonMessage = 'Bookings must be made at least @min in advance.';

  /**
   * Shown when the booking is too far ahead.
   */
  public string $tooFarMessage = 'Bookings can only be made up to @max in advance.';

  /**
   * {@inheritdoc}
   */
  public function validatedBy(): string {
    return BookingWindowValidator::class;
  }

}
