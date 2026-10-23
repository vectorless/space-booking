<?php

declare(strict_types=1);

namespace Drupal\space_booking\Plugin\Validation\Constraint;

use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\space_booking\AvailabilityChecker;
use Drupal\space_booking\BookingInterface;
use Drupal\space_booking\SpaceInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

/**
 * Validates that the booked spaces are free and bookable.
 */
final class SpaceAvailableValidator extends ConstraintValidator implements ContainerInjectionInterface {

  /**
   * A fixed pattern, so messages do not rely on a site's date formats.
   */
  private const string DATE_PATTERN = 'j M Y, H:i';

  public function __construct(
    private readonly AvailabilityChecker $availability,
    private readonly DateFormatterInterface $dateFormatter,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('space_booking.availability'),
      $container->get('date.formatter'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function validate(mixed $value, Constraint $constraint): void {
    if (!$value instanceof BookingInterface || !$constraint instanceof SpaceAvailableConstraint) {
      return;
    }

    $start = $value->getStart();
    $end = $value->getEnd();

    if ($end <= $start) {
      $this->context->buildViolation($constraint->orderMessage)
        ->atPath('end')
        ->addViolation();
      return;
    }

    // A cancelled or rejected booking releases its spaces, so it has nothing
    // left to clash with and should not be blocked from being saved.
    if (!$value->occupiesSpace()) {
      return;
    }

    foreach ($value->getSpaces() as $space) {
      \assert($space instanceof SpaceInterface);
      if (!$space->isBookable()) {
        $this->context->buildViolation($constraint->notBookableMessage)
          ->setParameter('%space', $space->label())
          ->atPath('spaces')
          ->addViolation();
      }
    }

    $id = $value->isNew() ? NULL : (int) $value->id();
    foreach ($this->availability->conflicts($value->getSpaceIds(), $start, $end, $id) as $conflict) {
      \assert($conflict instanceof BookingInterface);
      $names = array_map(
        static fn (SpaceInterface $space): string => (string) $space->label(),
        $conflict->getSpaces(),
      );
      $this->context->buildViolation($constraint->conflictMessage)
        ->setParameter('%space', implode(', ', $names))
        ->setParameter('@from', $this->dateFormatter->format($conflict->getStart(), 'custom', self::DATE_PATTERN))
        ->setParameter('@to', $this->dateFormatter->format($conflict->getEnd(), 'custom', self::DATE_PATTERN))
        ->atPath('spaces')
        ->addViolation();
    }
  }

}
