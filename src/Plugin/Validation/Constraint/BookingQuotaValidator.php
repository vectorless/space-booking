<?php

declare(strict_types=1);

namespace Drupal\space_booking\Plugin\Validation\Constraint;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\space_booking\AvailabilityChecker;
use Drupal\space_booking\BookingInterface;
use Drupal\space_booking\BookingSettings;
use Drupal\space_booking\TimeWindow;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

/**
 * Enforces the per-user weekly allowance.
 */
final class BookingQuotaValidator extends ConstraintValidator implements ContainerInjectionInterface {

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly AvailabilityChecker $availability,
    private readonly DateFormatterInterface $dateFormatter,
    private readonly string $timezone,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('config.factory'),
      $container->get('space_booking.availability'),
      $container->get('date.formatter'),
      (string) $container->get('config.factory')->get('system.date')->get('timezone.default'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function validate(mixed $value, Constraint $constraint): void {
    if (!$value instanceof BookingInterface || !$constraint instanceof BookingQuotaConstraint) {
      return;
    }
    if (!$value->getType()->isPublic() || !$value->occupiesSpace()) {
      return;
    }

    $settings = BookingSettings::fromConfig($this->configFactory);
    if ($settings->quotaPerWeek <= 0) {
      return;
    }

    $holder = $value->getHolder();
    if ($holder === NULL) {
      return;
    }

    [$weekStart, $weekEnd] = TimeWindow::weekBounds($value->getStart(), $this->timezone);
    $id = $value->isNew() ? NULL : (int) $value->id();
    $used = $this->availability->bookedSecondsFor((int) $holder->id(), $weekStart, $weekEnd, $id);
    $remaining = $settings->quotaPerWeek - $used;

    if ($value->getDuration() > $remaining) {
      $this->context->buildViolation($constraint->message)
        ->setParameter('@name', $holder->getDisplayName())
        ->setParameter('@quota', $this->dateFormatter->formatInterval($settings->quotaPerWeek))
        ->setParameter('@remaining', $remaining > 0 ? $this->dateFormatter->formatInterval($remaining) : '0')
        ->atPath('end')
        ->addViolation();
    }
  }

}
