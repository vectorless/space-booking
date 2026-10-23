<?php

declare(strict_types=1);

namespace Drupal\space_booking\Plugin\Validation\Constraint;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\space_booking\BookingInterface;
use Drupal\space_booking\BookingSettings;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

/**
 * Validates how far ahead a booking is being made.
 */
final class BookingWindowValidator extends ConstraintValidator implements ContainerInjectionInterface {

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly TimeInterface $time,
    private readonly DateFormatterInterface $dateFormatter,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('config.factory'),
      $container->get('datetime.time'),
      $container->get('date.formatter'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function validate(mixed $value, Constraint $constraint): void {
    if (!$value instanceof BookingInterface || !$constraint instanceof BookingWindowConstraint) {
      return;
    }
    if (!$value->getType()->isPublic() || !$value->occupiesSpace()) {
      return;
    }
    // Editing an existing booking must not fail because its start has since
    // passed: the window applies to making a booking, not to keeping one.
    if (!$value->isNew()) {
      return;
    }

    $settings = BookingSettings::fromConfig($this->configFactory);
    $now = $this->time->getRequestTime();
    $lead = $value->getStart() - $now;

    if ($lead < 0) {
      $this->context->buildViolation($constraint->pastMessage)->atPath('start')->addViolation();
      return;
    }

    if ($settings->minAdvance > 0 && $lead < $settings->minAdvance) {
      $this->context->buildViolation($constraint->tooSoonMessage)
        ->setParameter('@min', $this->dateFormatter->formatInterval($settings->minAdvance))
        ->atPath('start')
        ->addViolation();
    }

    if ($settings->maxAdvance > 0 && $lead > $settings->maxAdvance) {
      $this->context->buildViolation($constraint->tooFarMessage)
        ->setParameter('@max', $this->dateFormatter->formatInterval($settings->maxAdvance))
        ->atPath('start')
        ->addViolation();
    }
  }

}
