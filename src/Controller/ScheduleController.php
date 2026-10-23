<?php

declare(strict_types=1);

namespace Drupal\space_booking\Controller;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Url;
use Drupal\space_booking\AvailabilityChecker;
use Drupal\space_booking\BookingInterface;
use Drupal\space_booking\BookingSettings;
use Drupal\space_booking\SpaceInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * The schedule: a dates-by-spaces grid.
 */
final class ScheduleController implements ContainerInjectionInterface {

  use StringTranslationTrait;

  public function __construct(
    private readonly AvailabilityChecker $availability,
    private readonly EntityTypeManagerInterface $entityTypes,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly TimeInterface $time,
    private readonly DateFormatterInterface $dateFormatter,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('space_booking.availability'),
      $container->get('entity_type.manager'),
      $container->get('config.factory'),
      $container->get('datetime.time'),
      $container->get('date.formatter'),
    );
  }

  /**
   * Enabled spaces in display order.
   *
   * @return array<int, \Drupal\space_booking\SpaceInterface>
   *   Spaces keyed by ID.
   */
  private function spaces(): array {
    $storage = $this->entityTypes->getStorage('space_booking_space');
    $ids = $storage->getQuery()
      ->accessCheck(TRUE)
      ->condition('status', TRUE)
      ->sort('weight')
      ->sort('label')
      ->execute();

    $spaces = [];
    foreach ($storage->loadMultiple($ids) as $space) {
      \assert($space instanceof SpaceInterface);
      $spaces[(int) $space->id()] = $space;
    }
    return $spaces;
  }

  /**
   * Window of days the schedule covers, starting from local midnight today.
   *
   * @return array{int, int, int}
   *   Start timestamp, end timestamp and number of days.
   */
  private function window(): array {
    $days = (int) ($this->configFactory->get(BookingSettings::CONFIG_NAME)->get('schedule_days') ?? 14);
    $days = max(1, $days);
    $start = strtotime('today', $this->time->getRequestTime());
    return [$start, strtotime(sprintf('+%d days', $days), $start), $days];
  }

  /**
   * Render the grid.
   */
  public function grid(): array {
    [$start, $end, $days] = $this->window();
    $spaces = $this->spaces();
    $bookings = $this->availability->bookingsInWindow(array_keys($spaces), $start, $end);

    // Index bookings by space and by day so the template is a dumb renderer.
    $cells = [];
    foreach ($bookings as $booking) {
      \assert($booking instanceof BookingInterface);
      foreach ($booking->getSpaceIds() as $spaceId) {
        for ($day = 0; $day < $days; $day++) {
          $dayStart = strtotime(sprintf('+%d days', $day), $start);
          $dayEnd = strtotime('+1 day', $dayStart);
          if ($booking->overlaps($dayStart, $dayEnd)) {
            $cells[$spaceId][$day][] = [
              'label' => $booking->label(),
              'from' => $this->dateFormatter->format(max($booking->getStart(), $dayStart), 'custom', 'H:i'),
              'to' => $this->dateFormatter->format(min($booking->getEnd(), $dayEnd), 'custom', 'H:i'),
              'status' => $booking->getStatus()->value,
              'url' => $booking->access('view')
                ? $booking->toUrl()->toString()
                : NULL,
            ];
          }
        }
      }
    }

    $rows = [];
    for ($day = 0; $day < $days; $day++) {
      $dayStart = strtotime(sprintf('+%d days', $day), $start);
      $row = [
        'date' => $this->dateFormatter->format($dayStart, 'custom', 'D j M'),
        'timestamp' => $dayStart,
        'spaces' => [],
      ];
      foreach ($spaces as $spaceId => $space) {
        $row['spaces'][] = [
          'id' => $spaceId,
          'bookings' => $cells[$spaceId][$day] ?? [],
          'book_url' => $space->isBookable()
            ? Url::fromRoute('space_booking.book', [], [
              'query' => ['spaces' => [$spaceId], 'start' => $dayStart],
            ])->toString()
            : NULL,
        ];
      }
      $rows[] = $row;
    }

    return [
      '#theme' => 'space_booking_grid',
      '#spaces' => array_map(
        static fn (SpaceInterface $space): array => [
          'id' => (int) $space->id(),
          'label' => $space->label(),
          'bookable' => $space->isBookable(),
        ],
        array_values($spaces),
      ),
      '#rows' => $rows,
      '#attached' => ['library' => ['space_booking/grid']],
      '#cache' => [
        'tags' => ['space_booking_booking_list', 'space_booking_space_list'],
        'contexts' => ['user.permissions', 'timezone'],
        // The grid is anchored to "today", so it must not outlive today.
        'max-age' => 3600,
      ],
    ];
  }

  /**
   * The same data as JSON, for a calendar widget or an external consumer.
   */
  public function data(Request $request): JsonResponse {
    [$start, $end] = $this->window();
    $start = (int) $request->query->get('start', $start);
    $end = (int) $request->query->get('end', $end);

    $spaces = $this->spaces();
    $payload = ['spaces' => [], 'bookings' => []];

    foreach ($spaces as $id => $space) {
      $payload['spaces'][] = [
        'id' => $id,
        'label' => (string) $space->label(),
        'capacity' => $space->getCapacity(),
        'bookable' => $space->isBookable(),
      ];
    }

    foreach ($this->availability->bookingsInWindow(array_keys($spaces), $start, $end) as $booking) {
      \assert($booking instanceof BookingInterface);
      $payload['bookings'][] = [
        'id' => (int) $booking->id(),
        'spaces' => $booking->getSpaceIds(),
        'start' => $booking->getStart(),
        'end' => $booking->getEnd(),
        'status' => $booking->getStatus()->value,
        'type' => $booking->getType()->value,
        // Deliberately not the holder's name: the grid shows that an hour is
        // taken, not who took it.
        'label' => $booking->access('view') ? $booking->label() : NULL,
      ];
    }

    return new JsonResponse($payload);
  }

}
