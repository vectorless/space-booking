<?php

declare(strict_types=1);

namespace Drupal\space_booking\Hook;

use Drupal\Core\Url;
use Drupal\space_booking\Enum\BookingStatus;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\space_booking\BookingInterface;
use Drupal\space_booking\SpaceInterface;

/**
 * Hook implementations, as classes rather than procedural functions.
 */
final class SpaceBookingHooks {

  use StringTranslationTrait;

  public function __construct(
    private readonly DateFormatterInterface $dateFormatter,
  ) {}

  /**
   * Implements hook_theme().
   */
  #[Hook('theme')]
  public function theme(): array {
    return [
      'space_booking_grid' => [
        'variables' => [
          'spaces' => [],
          'rows' => [],
        ],
      ],
    ];
  }

  /**
   * Implements hook_mail().
   */
  #[Hook('mail')]
  public function mail(string $key, array &$message, array $params): void {
    $booking = $params['booking'] ?? NULL;
    if (!$booking instanceof BookingInterface) {
      return;
    }

    $options = ['langcode' => $message['langcode']];
    $spaces = implode(', ', array_map(
      static fn (SpaceInterface $space): string => (string) $space->label(),
      $booking->getSpaces(),
    ));
    $when = $this->dateFormatter->format($booking->getStart(), 'custom', 'l j F Y, H:i')
      . '–' . $this->dateFormatter->format($booking->getEnd(), 'custom', 'H:i');

    $arguments = [
      '@spaces' => $spaces,
      '@when' => $when,
      '@holder' => $booking->getHolder()?->getDisplayName() ?? '',
      '@title' => $booking->label(),
    ];

    [$audience, $event] = array_pad(explode('_', $key, 2), 2, '');

    $message['subject'] = match ($event) {
      'created' => $this->t('Booking received: @spaces', $arguments, $options),
      'approved' => $this->t('Booking confirmed: @spaces', $arguments, $options),
      'rejected' => $this->t('Booking not approved: @spaces', $arguments, $options),
      'cancelled' => $this->t('Booking cancelled: @spaces', $arguments, $options),
      default => $this->t('Booking update: @spaces', $arguments, $options),
    };

    $body = match ($event) {
      'created' => $this->t("@spaces\n@when\n\nThanks — we have your booking. If it needs approving you will hear separately.", $arguments, $options),
      'approved' => $this->t("@spaces\n@when\n\nYour booking is confirmed.", $arguments, $options),
      'rejected' => $this->t("@spaces\n@when\n\nSorry, this booking could not be approved. Please get in touch if you would like another time.", $arguments, $options),
      'cancelled' => $this->t("@spaces\n@when\n\nThis booking has been cancelled.", $arguments, $options),
      default => $this->t("@spaces\n@when", $arguments, $options),
    };

    if ($audience === 'venue') {
      $body = $this->t("@holder — @title\n@spaces\n@when", $arguments, $options);
    }

    $message['body'][] = $body;
  }

  /**
   * Implements hook_entity_operation() for the approval shortcuts.
   */
  #[Hook('entity_operation')]
  public function entityOperation(EntityInterface $entity): array {
    if (!$entity instanceof BookingInterface) {
      return [];
    }
    if ($entity->getStatus() !== BookingStatus::Pending) {
      return [];
    }

    return [
      'approve' => [
        'title' => $this->t('Approve'),
        'weight' => -10,
        'url' => Url::fromRoute('space_booking.approve', [
          'space_booking_booking' => $entity->id(),
        ]),
      ],
      'reject' => [
        'title' => $this->t('Reject'),
        'weight' => -9,
        'url' => Url::fromRoute('space_booking.reject', [
          'space_booking_booking' => $entity->id(),
        ]),
      ],
    ];
  }

}
