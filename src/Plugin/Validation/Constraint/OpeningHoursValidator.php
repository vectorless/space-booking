<?php

declare(strict_types=1);

namespace Drupal\space_booking\Plugin\Validation\Constraint;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\space_booking\BookingInterface;
use Drupal\space_booking\BookingSettings;
use Drupal\space_booking\TimeWindow;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

/**
 * Keeps bookings within the venue's opening hours.
 */
final class OpeningHoursValidator extends ConstraintValidator implements ContainerInjectionInterface {

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly string $timezone,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('config.factory'),
      (string) $container->get('config.factory')->get('system.date')->get('timezone.default'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function validate(mixed $value, Constraint $constraint): void {
    if (!$value instanceof BookingInterface || !$constraint instanceof OpeningHoursConstraint) {
      return;
    }
    // Closures and internal use are exactly the things that need to be
    // recorded outside opening hours.
    if (!$value->getType()->isPublic() || !$value->occupiesSpace()) {
      return;
    }

    $settings = BookingSettings::fromConfig($this->configFactory);
    $window = TimeWindow::fromTimestamps($value->getStart(), $value->getEnd(), $this->timezone);

    if (!$window->isSameDay()) {
      $this->context->buildViolation($constraint->overnightMessage)->atPath('end')->addViolation();
      return;
    }

    $hours = $settings->hoursFor($window->weekday());
    if ($hours === NULL) {
      $this->context->buildViolation($constraint->closedMessage)
        ->setParameter('@day', $window->dayName())
        ->atPath('start')
        ->addViolation();
      return;
    }

    if ($window->startMinutes() < $hours['open'] || $window->endMinutes() > $hours['close']) {
      $this->context->buildViolation($constraint->outsideMessage)
        ->setParameter('@day', $window->dayName())
        ->setParameter('@open', TimeWindow::formatMinutes($hours['open']))
        ->setParameter('@close', TimeWindow::formatMinutes($hours['close']))
        ->atPath('start')
        ->addViolation();
    }
  }

}
