<?php

declare(strict_types=1);

namespace Drupal\space_booking\Plugin\Validation\Constraint;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\space_booking\BookingInterface;
use Drupal\space_booking\BookingSettings;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

/**
 * Validates booking length and slot alignment.
 */
final class BookingDurationValidator extends ConstraintValidator implements ContainerInjectionInterface {

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly DateFormatterInterface $dateFormatter,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('config.factory'),
      $container->get('date.formatter'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function validate(mixed $value, Constraint $constraint): void {
    if (!$value instanceof BookingInterface || !$constraint instanceof BookingDurationConstraint) {
      return;
    }
    // Staff blocking a space for maintenance should not be held to the limits
    // written for members booking a room.
    if (!$value->getType()->isPublic() || !$value->occupiesSpace()) {
      return;
    }

    $settings = BookingSettings::fromConfig($this->configFactory);
    $duration = $value->getDuration();
    if ($duration <= 0) {
      return;
    }

    if ($settings->minDuration > 0 && $duration < $settings->minDuration) {
      $this->context->buildViolation($constraint->tooShortMessage)
        ->setParameter('@min', $this->dateFormatter->formatInterval($settings->minDuration))
        ->atPath('end')
        ->addViolation();
    }

    if ($settings->maxDuration > 0 && $duration > $settings->maxDuration) {
      $this->context->buildViolation($constraint->tooLongMessage)
        ->setParameter('@max', $this->dateFormatter->formatInterval($settings->maxDuration))
        ->atPath('end')
        ->addViolation();
    }

    $granularity = $settings->granularity;
    if ($granularity > 0 && ($value->getStart() % $granularity !== 0 || $value->getEnd() % $granularity !== 0)) {
      $this->context->buildViolation($constraint->granularityMessage)
        ->setParameter('@minute', (string) intdiv($granularity, 60))
        ->atPath('start')
        ->addViolation();
    }
  }

}
