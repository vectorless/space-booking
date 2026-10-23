<?php

declare(strict_types=1);

namespace Drupal\Tests\space_booking\Kernel;

use Drupal\space_booking\TimeWindow;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\space_booking\Traits\SpaceBookingTestTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\space_booking\AvailabilityChecker;
use Drupal\space_booking\Enum\BookingStatus;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * The availability service on its own, away from entity validation.
 */
#[Group('space_booking')]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(FALSE)]
final class AvailabilityCheckerTest extends KernelTestBase {

  use SpaceBookingTestTrait;
  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system', 'user', 'field', 'options', 'datetime', 'text', 'filter',
    'taxonomy', 'space_booking',
  ];

  /**
   * The availability service under test.
   */
  private AvailabilityChecker $availability;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('taxonomy_term');
    $this->installEntitySchema('space_booking_space');
    $this->installEntitySchema('space_booking_booking');
    $this->installConfig(['space_booking']);

    $this->config('system.date')->set('timezone.default', $this->venueTimezone)->save();
    $this->openAllHours();
    $this->setBookingConfig(['max_advance_days' => 0, 'min_duration_minutes' => 0]);

    $this->availability = $this->container->get('space_booking.availability');
  }

  /**
   * A space with nothing in it is free.
   */
  public function testEmptySpaceIsAvailable(): void {
    $space = $this->createSpace();

    $this->assertTrue($this->availability->isAvailable(
      [(int) $space->id()],
      $this->at('+1 Tuesday 10:00'),
      $this->at('+1 Tuesday 11:00'),
    ));
  }

  /**
   * An occupied space is not.
   */
  public function testOccupiedSpaceIsNotAvailable(): void {
    $space = $this->createSpace();
    $this->createBooking([$space], '+1 Tuesday 10:00', '+1 Tuesday 12:00');

    $this->assertFalse($this->availability->isAvailable(
      [(int) $space->id()],
      $this->at('+1 Tuesday 11:00'),
      $this->at('+1 Tuesday 13:00'),
    ));
  }

  /**
   * The booking being edited can be excluded from its own check.
   */
  public function testIgnoringBookingMakesItsSlotFree(): void {
    $space = $this->createSpace();
    $booking = $this->createBooking([$space], '+1 Tuesday 10:00', '+1 Tuesday 12:00');

    $this->assertFalse($this->availability->isAvailable(
      [(int) $space->id()],
      $this->at('+1 Tuesday 10:00'),
      $this->at('+1 Tuesday 12:00'),
    ));
    $this->assertTrue($this->availability->isAvailable(
      [(int) $space->id()],
      $this->at('+1 Tuesday 10:00'),
      $this->at('+1 Tuesday 12:00'),
      (int) $booking->id(),
    ));
  }

  /**
   * Conflicting spaces are pulled in both directions.
   */
  public function testExpandConflictingSpacesIsSymmetrical(): void {
    $half = $this->createSpace('Half');
    $whole = $this->createSpace('Whole', ['conflicts_with' => [(int) $half->id()]]);

    $fromWhole = $this->availability->expandConflictingSpaces([(int) $whole->id()]);
    $fromHalf = $this->availability->expandConflictingSpaces([(int) $half->id()]);

    sort($fromWhole);
    sort($fromHalf);
    $expected = [(int) $half->id(), (int) $whole->id()];
    sort($expected);

    $this->assertSame($expected, $fromWhole, 'Booking the whole must consider the half.');
    $this->assertSame($expected, $fromHalf, 'Booking the half must consider the whole.');
  }

  /**
   * The grid query returns what is booked in the window.
   */
  public function testBookingsInWindow(): void {
    $space = $this->createSpace();
    $inside = $this->createBooking([$space], '+1 Tuesday 10:00', '+1 Tuesday 11:00');
    $this->createBooking([$space], '+8 Tuesday 10:00', '+8 Tuesday 11:00');

    $found = $this->availability->bookingsInWindow(
      [(int) $space->id()],
      $this->at('+1 Tuesday 00:00'),
      $this->at('+2 Wednesday 00:00'),
    );

    $this->assertSame([(int) $inside->id()], array_keys($found));
  }

  /**
   * Cancelled bookings vanish from the grid.
   */
  public function testCancelledBookingsAreNotShown(): void {
    $space = $this->createSpace();
    $booking = $this->createBooking([$space], '+1 Tuesday 10:00', '+1 Tuesday 11:00');
    $booking->setStatus(BookingStatus::Cancelled)->save();

    $this->assertSame([], $this->availability->bookingsInWindow(
      [(int) $space->id()],
      $this->at('+1 Tuesday 00:00'),
      $this->at('+2 Wednesday 00:00'),
    ));
  }

  /**
   * Usage is counted per person.
   */
  public function testBookedSecondsFor(): void {
    $user = $this->createUser();
    $space = $this->createSpace();
    $this->createBooking([$space], '+1 Tuesday 10:00', '+1 Tuesday 12:00', ['holder' => $user->id()]);

    [$weekStart, $weekEnd] = TimeWindow::weekBounds(
      $this->at('+1 Tuesday 10:00'),
      $this->venueTimezone,
    );

    $this->assertSame(7200, $this->availability->bookedSecondsFor((int) $user->id(), $weekStart, $weekEnd));
  }

  /**
   * Only the part of a booking inside the period is counted.
   *
   * Otherwise a booking straddling midnight on Sunday would be charged in
   * full to both weeks and quietly double a member's usage.
   */
  public function testBookedSecondsClipsToThePeriod(): void {
    $user = $this->createUser();
    $space = $this->createSpace();
    $this->createBooking([$space], '+1 Tuesday 10:00', '+1 Tuesday 14:00', ['holder' => $user->id()]);

    $counted = $this->availability->bookedSecondsFor(
      (int) $user->id(),
      $this->at('+1 Tuesday 12:00'),
      $this->at('+1 Tuesday 13:00'),
    );

    $this->assertSame(3600, $counted, 'Only the overlapping hour should count.');
  }

  /**
   * An empty space list is answered without touching the database.
   */
  public function testEmptyInputIsHandled(): void {
    $this->assertSame([], $this->availability->conflicts([], 0, 100));
    $this->assertSame([], $this->availability->expandConflictingSpaces([]));
    $this->assertSame([], $this->availability->bookingsInWindow([], 0, 100));
  }

  /**
   * A zero-length or reversed range cannot conflict with anything.
   */
  public function testReversedRangeHasNoConflicts(): void {
    $space = $this->createSpace();
    $this->createBooking([$space], '+1 Tuesday 10:00', '+1 Tuesday 12:00');

    $this->assertSame([], $this->availability->conflicts(
      [(int) $space->id()],
      $this->at('+1 Tuesday 12:00'),
      $this->at('+1 Tuesday 10:00'),
    ));
  }

}
