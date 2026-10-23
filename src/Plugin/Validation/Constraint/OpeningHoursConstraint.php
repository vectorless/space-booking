<?php

declare(strict_types=1);

namespace Drupal\space_booking\Plugin\Validation\Constraint;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Validation\Attribute\Constraint;
use Symfony\Component\Validator\Constraint as SymfonyConstraint;

/**
 * Keeps bookings inside the venue's opening hours.
 */
#[Constraint(
  id: 'OpeningHours',
  label: new TranslatableMarkup('Opening hours', [], ['context' => 'Validation']),
  type: ['entity'],
)]
final class OpeningHoursConstraint extends SymfonyConstraint {

  /**
   * Shown when the venue is shut that day.
   */
  public string $closedMessage = 'The venue is closed on @day.';

  /**
   * Shown when the booking falls outside opening hours.
   */
  public string $outsideMessage = 'On @day the venue is open from @open to @close.';

  /**
   * Shown when a booking would run across a day boundary.
   */
  public string $overnightMessage = 'A booking cannot run past midnight.';

  /**
   * {@inheritdoc}
   */
  public function validatedBy(): string {
    return OpeningHoursValidator::class;
  }

}
