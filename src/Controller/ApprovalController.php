<?php

declare(strict_types=1);

namespace Drupal\space_booking\Controller;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Drupal\space_booking\BookingInterface;
use Drupal\space_booking\BookingManager;
use Drupal\space_booking\SpaceInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * The queue of bookings waiting for a decision.
 */
final class ApprovalController implements ContainerInjectionInterface {

  use StringTranslationTrait;

  public function __construct(
    private readonly BookingManager $bookingManager,
    private readonly DateFormatterInterface $dateFormatter,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('space_booking.manager'),
      $container->get('date.formatter'),
    );
  }

  /**
   * Render the pending queue.
   */
  public function queue(): array {
    $rows = [];
    foreach ($this->bookingManager->pending() as $booking) {
      \assert($booking instanceof BookingInterface);
      $spaces = array_map(
        static fn (SpaceInterface $space): string => (string) $space->label(),
        $booking->getSpaces(),
      );

      $rows[] = [
        'holder' => $booking->getHolder()?->getDisplayName() ?? '',
        'spaces' => implode(', ', $spaces),
        'when' => $this->t('@from to @to', [
          '@from' => $this->dateFormatter->format($booking->getStart(), 'short'),
          '@to' => $this->dateFormatter->format($booking->getEnd(), 'custom', 'H:i'),
        ]),
        'title' => $booking->label(),
        'actions' => [
          'data' => [
            '#theme' => 'item_list',
            '#items' => [
              Link::fromTextAndUrl($this->t('Approve'), Url::fromRoute('space_booking.approve', ['space_booking_booking' => $booking->id()])),
              Link::fromTextAndUrl($this->t('Reject'), Url::fromRoute('space_booking.reject', ['space_booking_booking' => $booking->id()])),
            ],
          ],
        ],
      ];
    }

    return [
      '#type' => 'table',
      '#header' => [
        $this->t('Held by'),
        $this->t('Spaces'),
        $this->t('When'),
        $this->t('Title'),
        $this->t('Actions'),
      ],
      '#rows' => $rows,
      '#empty' => $this->t('Nothing is waiting for approval.'),
      '#cache' => [
        'tags' => ['space_booking_booking_list'],
        'contexts' => ['user.permissions'],
      ],
    ];
  }

}
