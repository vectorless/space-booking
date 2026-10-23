<?php

declare(strict_types=1);

namespace Drupal\space_booking\Plugin\Validation\Constraint;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Validation\Attribute\Constraint;
use Symfony\Component\Validator\Constraint as SymfonyConstraint;

/**
 * The spaces must be free, bookable, and the time range must make sense.
 */
#[Constraint(
  id: 'SpaceAvailable',
  label: new TranslatableMarkup('Space is available', [], ['context' => 'Validation']),
  type: ['entity'],
)]
final class SpaceAvailableConstraint extends SymfonyConstraint {

  /**
   * Shown when another booking already holds the space.
   */
  public string $conflictMessage = '%space is already booked between @from and @to.';

  /**
   * Shown when the space exists but is not open to reservations.
   */
  public string $notBookableMessage = '%space cannot be booked.';

  /**
   * Shown when the end is not after the start.
   */
  public string $orderMessage = 'The end time must be after the start time.';

  /**
   * {@inheritdoc}
   */
  public function validatedBy(): string {
    return SpaceAvailableValidator::class;
  }

}
