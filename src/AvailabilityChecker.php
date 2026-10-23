<?php

declare(strict_types=1);

namespace Drupal\space_booking;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\space_booking\Enum\BookingStatus;

/**
 * Answers the only question that really matters: is this space free?
 *
 * Everything else in the module is presentation. This service is the single
 * authority on whether two bookings collide, so the booking form, the REST
 * endpoint and the approval queue cannot drift apart.
 */
final class AvailabilityChecker {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * Settings, read fresh so configuration changes take effect immediately.
   */
  private function settings(): BookingSettings {
    return BookingSettings::fromConfig($this->configFactory);
  }

  /**
   * Expand a set of spaces to include everything they physically conflict with.
   *
   * Booking the whole hall has to block both halves. The relationship is
   * stored on one side but has to apply in both directions, so this walks it
   * forwards and backwards.
   *
   * @param list<int> $spaceIds
   *   The space IDs being booked.
   *
   * @return list<int>
   *   The given spaces plus every space that cannot be used alongside them.
   */
  public function expandConflictingSpaces(array $spaceIds): array {
    if ($spaceIds === []) {
      return [];
    }
    $storage = $this->entityTypeManager->getStorage('space_booking_space');
    $expanded = array_fill_keys($spaceIds, TRUE);

    // Forwards: spaces these ones declare a conflict with.
    foreach ($storage->loadMultiple($spaceIds) as $space) {
      \assert($space instanceof SpaceInterface);
      foreach ($space->getConflictingSpaceIds() as $conflictId) {
        $expanded[$conflictId] = TRUE;
      }
    }

    // Backwards: spaces that declare a conflict with these ones.
    $reverse = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('conflicts_with', $spaceIds, 'IN')
      ->execute();
    foreach ($reverse as $id) {
      $expanded[(int) $id] = TRUE;
    }

    return array_map('intval', array_keys($expanded));
  }

  /**
   * Bookings that would collide with the given spaces and time range.
   *
   * @param list<int> $spaceIds
   *   Spaces to check.
   * @param int $start
   *   Range start timestamp.
   * @param int $end
   *   Range end timestamp, exclusive.
   * @param int|null $ignoreBookingId
   *   A booking to exclude, so editing a booking does not clash with itself.
   *
   * @return array<int, \Drupal\space_booking\BookingInterface>
   *   Conflicting bookings keyed by ID.
   */
  public function conflicts(array $spaceIds, int $start, int $end, ?int $ignoreBookingId = NULL): array {
    if ($spaceIds === [] || $end <= $start) {
      return [];
    }

    $buffer = $this->settings()->buffer;
    $spaceIds = $this->expandConflictingSpaces($spaceIds);

    $storage = $this->entityTypeManager->getStorage('space_booking_booking');
    $query = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('spaces', $spaceIds, 'IN')
      ->condition('status', BookingStatus::occupyingValues(), 'IN')
      // Half-open overlap, widened by the buffer on both sides so that a gap
      // is enforced rather than merely preferred.
      ->condition('start', $end + $buffer, '<')
      ->condition('end', $start - $buffer, '>');

    if ($ignoreBookingId !== NULL) {
      $query->condition('id', $ignoreBookingId, '<>');
    }

    $ids = $query->execute();
    if ($ids === []) {
      return [];
    }

    $conflicts = [];
    foreach ($storage->loadMultiple($ids) as $booking) {
      \assert($booking instanceof BookingInterface);
      $conflicts[(int) $booking->id()] = $booking;
    }
    return $conflicts;
  }

  /**
   * Whether the given spaces are free for the whole range.
   *
   * @param list<int> $spaceIds
   *   Spaces to check.
   * @param int $start
   *   Range start timestamp.
   * @param int $end
   *   Range end timestamp, exclusive.
   * @param int|null $ignoreBookingId
   *   A booking to exclude from the check.
   */
  public function isAvailable(array $spaceIds, int $start, int $end, ?int $ignoreBookingId = NULL): bool {
    return $this->conflicts($spaceIds, $start, $end, $ignoreBookingId) === [];
  }

  /**
   * Bookings occupying any of the given spaces within a window.
   *
   * Used to paint the schedule, so it deliberately does not apply the buffer:
   * the grid should show what is booked, not what is unbookable.
   *
   * @param list<int> $spaceIds
   *   Spaces to load bookings for.
   * @param int $start
   *   Window start timestamp.
   * @param int $end
   *   Window end timestamp, exclusive.
   *
   * @return array<int, \Drupal\space_booking\BookingInterface>
   *   Bookings keyed by ID, ordered by start time.
   */
  public function bookingsInWindow(array $spaceIds, int $start, int $end): array {
    if ($spaceIds === []) {
      return [];
    }
    $storage = $this->entityTypeManager->getStorage('space_booking_booking');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('spaces', $spaceIds, 'IN')
      ->condition('status', BookingStatus::occupyingValues(), 'IN')
      ->condition('start', $end, '<')
      ->condition('end', $start, '>')
      ->sort('start')
      ->execute();

    $bookings = [];
    foreach ($storage->loadMultiple($ids) as $booking) {
      \assert($booking instanceof BookingInterface);
      $bookings[(int) $booking->id()] = $booking;
    }
    return $bookings;
  }

  /**
   * Seconds a user has already booked within a period.
   *
   * @param int $uid
   *   The holder's user ID.
   * @param int $start
   *   Period start timestamp.
   * @param int $end
   *   Period end timestamp, exclusive.
   * @param int|null $ignoreBookingId
   *   A booking to exclude, so editing does not count itself twice.
   */
  public function bookedSecondsFor(int $uid, int $start, int $end, ?int $ignoreBookingId = NULL): int {
    $storage = $this->entityTypeManager->getStorage('space_booking_booking');
    $query = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('holder', $uid)
      ->condition('status', BookingStatus::occupyingValues(), 'IN')
      ->condition('start', $end, '<')
      ->condition('end', $start, '>');

    if ($ignoreBookingId !== NULL) {
      $query->condition('id', $ignoreBookingId, '<>');
    }

    $total = 0;
    foreach ($storage->loadMultiple($query->execute()) as $booking) {
      \assert($booking instanceof BookingInterface);
      // Count only the part that falls inside the period, so a booking
      // straddling the week boundary is not charged to both weeks in full.
      $total += max(0, min($booking->getEnd(), $end) - max($booking->getStart(), $start));
    }
    return $total;
  }

}
