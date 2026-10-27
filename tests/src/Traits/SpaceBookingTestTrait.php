<?php

declare(strict_types=1);

namespace Drupal\Tests\space_booking\Traits;

use Drupal\space_booking\BookingInterface;
use Drupal\space_booking\Enum\BookingStatus;
use Drupal\space_booking\Enum\BookingType;
use Drupal\space_booking\SpaceInterface;

/**
 * Builders for the entities every booking test needs.
 */
trait SpaceBookingTestTrait {

  /**
   * The venue timezone the tests reason in.
   */
  protected string $venueTimezone = 'Europe/London';

  /**
   * Create a space.
   *
   * @param string $label
   *   The space name.
   * @param array<string, mixed> $values
   *   Field values to override.
   */
  protected function createSpace(string $label = 'Main Hall', array $values = []): SpaceInterface {
    $space = $this->container->get('entity_type.manager')
      ->getStorage('space_booking_space')
      ->create($values + [
        'label' => $label,
        'status' => TRUE,
        'bookable' => TRUE,
        'requires_approval' => FALSE,
      ]);
    $space->save();
    \assert($space instanceof SpaceInterface);
    return $space;
  }

  /**
   * A timestamp for a local wall-clock time.
   *
   * Tests should say "Tuesday at ten", not juggle epoch seconds.
   *
   * Weekday fixtures are anchored on a Monday rather than on today, because
   * PHP reads "+1 Tuesday" on a Tuesday as *this* Tuesday: run the suite on a
   * Tuesday afternoon and every future fixture quietly becomes a past one.
   * Counting forward from next Monday, or back from last Monday, keeps each
   * fixture on the side of now that the test meant.
   */
  protected function at(string $local): int {
    $zone = new \DateTimeZone($this->venueTimezone);

    // Only weekday fixtures: "+2 hours" and "+30 days" mean exactly what they
    // say and must stay relative to now.
    if (preg_match('/^([-+])\d+ (?:mon|tue|wed|thu|fri|sat|sun)/i', $local, $matches) === 1) {
      $anchor = $matches[1] === '+' ? 'next Monday 00:00' : 'last Monday 00:00';
      return (new \DateTimeImmutable($anchor, $zone))->modify($local)->getTimestamp();
    }

    return (new \DateTimeImmutable($local, $zone))->getTimestamp();
  }

  /**
   * Create a booking without saving it, so validation can be inspected.
   *
   * @param list<\Drupal\space_booking\SpaceInterface> $spaces
   *   Spaces to book.
   * @param string $start
   *   Local start time.
   * @param string $end
   *   Local end time.
   * @param array<string, mixed> $values
   *   Field values to override.
   */
  protected function buildBooking(array $spaces, string $start, string $end, array $values = []): BookingInterface {
    $booking = $this->container->get('entity_type.manager')
      ->getStorage('space_booking_booking')
      ->create($values + [
        'spaces' => array_map(static fn (SpaceInterface $space): int => (int) $space->id(), $spaces),
        'start' => $this->at($start),
        'end' => $this->at($end),
        'booking_type' => BookingType::User->value,
        'status' => BookingStatus::Confirmed->value,
      ]);
    \assert($booking instanceof BookingInterface);
    return $booking;
  }

  /**
   * Create and save a booking.
   *
   * @param list<\Drupal\space_booking\SpaceInterface> $spaces
   *   Spaces to book.
   * @param string $start
   *   Local start time.
   * @param string $end
   *   Local end time.
   * @param array<string, mixed> $values
   *   Field values to override.
   */
  protected function createBooking(array $spaces, string $start, string $end, array $values = []): BookingInterface {
    $booking = $this->buildBooking($spaces, $start, $end, $values);
    $booking->save();
    return $booking;
  }

  /**
   * Assert a booking validates cleanly.
   */
  protected function assertValid(BookingInterface $booking, string $message = ''): void {
    $violations = $booking->validate();

    // Report what actually went wrong rather than "expected 0, got 2".
    $reasons = [];
    foreach ($violations as $violation) {
      $reasons[] = $violation->getPropertyPath() . ': ' . strip_tags((string) $violation->getMessage());
    }

    $this->assertCount(
      0,
      $violations,
      ($message !== '' ? $message . ' ' : '') . 'Unexpected violations: ' . implode(' | ', $reasons),
    );
  }

  /**
   * Assert a booking is rejected, optionally matching the message.
   */
  protected function assertInvalid(BookingInterface $booking, ?string $contains = NULL): void {
    $violations = $booking->validate();
    $this->assertGreaterThan(0, $violations->count(), 'Expected the booking to be rejected.');

    if ($contains !== NULL) {
      $messages = [];
      foreach ($violations as $violation) {
        $messages[] = (string) $violation->getMessage();
      }
      $this->assertStringContainsString(
        $contains,
        implode(' | ', $messages),
        'Expected a violation mentioning "' . $contains . '".',
      );
    }
  }

  /**
   * Set module configuration for a test.
   *
   * @param array<string, mixed> $values
   *   Settings keyed by config key.
   */
  protected function setBookingConfig(array $values): void {
    $config = $this->config('space_booking.settings');
    foreach ($values as $key => $value) {
      $config->set($key, $value);
    }
    $config->save();
  }

  /**
   * Open the venue around the clock, every day.
   *
   * Most tests are about conflicts or durations, not opening hours, and they
   * should not fail because a fixture happens to fall on a Sunday.
   */
  protected function openAllHours(): void {
    $hours = [];
    foreach (range(1, 7) as $weekday) {
      $hours[$weekday] = ['closed' => FALSE, 'open' => 0, 'close' => 1440];
    }
    $this->setBookingConfig(['opening_hours' => $hours]);
  }

}
