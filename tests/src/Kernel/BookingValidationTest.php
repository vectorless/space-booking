<?php

declare(strict_types=1);

namespace Drupal\Tests\space_booking\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\space_booking\Traits\SpaceBookingTestTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\space_booking\Enum\BookingStatus;
use Drupal\space_booking\Enum\BookingType;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * The rules that decide whether a booking may be saved.
 */
#[Group('space_booking')]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(FALSE)]
final class BookingValidationTest extends KernelTestBase {

  use SpaceBookingTestTrait;
  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'options',
    'datetime',
    'text',
    'filter',
    'taxonomy',
    'space_booking',
  ];

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
    // Remove the advance-window ceiling so fixtures can sit wherever is
    // convenient; the window has its own dedicated tests below.
    $this->setBookingConfig(['max_advance_days' => 0, 'min_duration_minutes' => 0]);
  }

  /**
   * A booking in a free space is accepted.
   */
  public function testFreeSpaceIsBookable(): void {
    $space = $this->createSpace();

    $this->assertValid($this->buildBooking([$space], '+1 Tuesday 10:00', '+1 Tuesday 11:00'));
  }

  /**
   * Two bookings cannot hold the same space at the same time.
   */
  public function testOverlappingBookingIsRejected(): void {
    $space = $this->createSpace();
    $this->createBooking([$space], '+1 Tuesday 10:00', '+1 Tuesday 12:00');

    $this->assertInvalid(
      $this->buildBooking([$space], '+1 Tuesday 11:00', '+1 Tuesday 13:00'),
      'already booked',
    );
  }

  /**
   * Bookings that merely touch do not overlap.
   *
   * A room free from 11:00 should be bookable at 11:00; treating the end as
   * inclusive would lose an hour between every pair of bookings.
   */
  public function testTouchingBookingsAreAllowed(): void {
    $space = $this->createSpace();
    $this->createBooking([$space], '+1 Tuesday 10:00', '+1 Tuesday 11:00');

    $this->assertValid(
      $this->buildBooking([$space], '+1 Tuesday 11:00', '+1 Tuesday 12:00'),
      'A booking starting when another ends should be allowed.',
    );
  }

  /**
   * Different spaces at the same time do not collide.
   */
  public function testDifferentSpacesDoNotCollide(): void {
    $hall = $this->createSpace('Hall');
    $studio = $this->createSpace('Studio');
    $this->createBooking([$hall], '+1 Tuesday 10:00', '+1 Tuesday 12:00');

    $this->assertValid($this->buildBooking([$studio], '+1 Tuesday 10:00', '+1 Tuesday 12:00'));
  }

  /**
   * Cancelling frees the slot for somebody else.
   */
  public function testCancelledBookingReleasesTheSlot(): void {
    $space = $this->createSpace();
    $first = $this->createBooking([$space], '+1 Tuesday 10:00', '+1 Tuesday 12:00');

    $this->assertInvalid($this->buildBooking([$space], '+1 Tuesday 10:00', '+1 Tuesday 12:00'));

    $first->setStatus(BookingStatus::Cancelled)->save();

    $this->assertValid(
      $this->buildBooking([$space], '+1 Tuesday 10:00', '+1 Tuesday 12:00'),
      'A cancelled booking should no longer block the space.',
    );
  }

  /**
   * A pending request still holds its slot.
   */
  public function testPendingBookingStillBlocks(): void {
    $space = $this->createSpace();
    $this->createBooking([$space], '+1 Tuesday 10:00', '+1 Tuesday 12:00', [
      'status' => BookingStatus::Pending->value,
    ]);

    $this->assertInvalid(
      $this->buildBooking([$space], '+1 Tuesday 10:00', '+1 Tuesday 12:00'),
      'already booked',
    );
  }

  /**
   * Editing a booking does not clash with itself.
   */
  public function testEditingBookingDoesNotConflictWithItself(): void {
    $space = $this->createSpace();
    $booking = $this->createBooking([$space], '+1 Tuesday 10:00', '+1 Tuesday 12:00');

    $booking->set('title', 'Renamed');

    $this->assertValid($booking, 'A saved booking must not be treated as its own conflict.');
  }

  /**
   * The end must come after the start.
   */
  public function testEndMustFollowStart(): void {
    $space = $this->createSpace();

    $this->assertInvalid(
      $this->buildBooking([$space], '+1 Tuesday 12:00', '+1 Tuesday 10:00'),
      'end time must be after',
    );
  }

  /**
   * A space marked unbookable refuses reservations.
   */
  public function testUnbookableSpaceIsRefused(): void {
    $space = $this->createSpace('Caretaker Desk', ['bookable' => FALSE]);

    $this->assertInvalid(
      $this->buildBooking([$space], '+1 Tuesday 10:00', '+1 Tuesday 11:00'),
      'cannot be booked',
    );
  }

  /**
   * Buffer time keeps a gap between bookings.
   */
  public function testBufferTimeIsEnforced(): void {
    $this->setBookingConfig(['buffer_minutes' => 30]);
    $space = $this->createSpace();
    $this->createBooking([$space], '+1 Tuesday 10:00', '+1 Tuesday 11:00');

    $this->assertInvalid(
      $this->buildBooking([$space], '+1 Tuesday 11:15', '+1 Tuesday 12:00'),
      'already booked',
    );
    $this->assertValid(
      $this->buildBooking([$space], '+1 Tuesday 11:30', '+1 Tuesday 12:00'),
      'A booking beyond the buffer should be allowed.',
    );
  }

  /**
   * Booking a whole hall blocks the halves it divides into.
   */
  public function testConflictingSpacesBlockEachOther(): void {
    $hallA = $this->createSpace('Hall A');
    $hallB = $this->createSpace('Hall B');
    $whole = $this->createSpace('Whole Hall', [
      'conflicts_with' => [(int) $hallA->id(), (int) $hallB->id()],
    ]);

    $this->createBooking([$whole], '+1 Tuesday 10:00', '+1 Tuesday 12:00');

    $this->assertInvalid(
      $this->buildBooking([$hallA], '+1 Tuesday 10:00', '+1 Tuesday 11:00'),
      'already booked',
    );
  }

  /**
   * The conflict works in the other direction too.
   *
   * The relationship is stored on one entity but describes a physical fact
   * about both, so booking a half must block the whole.
   */
  public function testConflictingSpacesBlockInReverse(): void {
    $hallA = $this->createSpace('Hall A');
    $whole = $this->createSpace('Whole Hall', ['conflicts_with' => [(int) $hallA->id()]]);

    $this->createBooking([$hallA], '+1 Tuesday 10:00', '+1 Tuesday 12:00');

    $this->assertInvalid(
      $this->buildBooking([$whole], '+1 Tuesday 10:00', '+1 Tuesday 11:00'),
      'already booked',
    );
  }

  /**
   * One booking may span several spaces.
   */
  public function testMultiSpaceBookingBlocksAllOfThem(): void {
    $hall = $this->createSpace('Hall');
    $kitchen = $this->createSpace('Kitchen');
    $this->createBooking([$hall, $kitchen], '+1 Tuesday 10:00', '+1 Tuesday 12:00');

    $this->assertInvalid($this->buildBooking([$kitchen], '+1 Tuesday 10:00', '+1 Tuesday 11:00'));
  }

  /**
   * Bookings shorter than the minimum are refused.
   */
  public function testMinimumDuration(): void {
    $this->setBookingConfig(['min_duration_minutes' => 60, 'granularity_minutes' => 15]);
    $space = $this->createSpace();

    $this->assertInvalid(
      $this->buildBooking([$space], '+1 Tuesday 10:00', '+1 Tuesday 10:30'),
      'at least',
    );
    $this->assertValid($this->buildBooking([$space], '+1 Tuesday 10:00', '+1 Tuesday 11:00'));
  }

  /**
   * Bookings longer than the maximum are refused.
   */
  public function testMaximumDuration(): void {
    $this->setBookingConfig(['max_duration_minutes' => 120]);
    $space = $this->createSpace();

    $this->assertInvalid(
      $this->buildBooking([$space], '+1 Tuesday 10:00', '+1 Tuesday 13:00'),
      'at most',
    );
  }

  /**
   * Bookings must land on the slot grid.
   */
  public function testGranularityIsEnforced(): void {
    $this->setBookingConfig(['granularity_minutes' => 30]);
    $space = $this->createSpace();

    $this->assertInvalid(
      $this->buildBooking([$space], '+1 Tuesday 10:10', '+1 Tuesday 11:10'),
      'boundaries',
    );
  }

  /**
   * Internal use escapes the rules written for members.
   *
   * Staff must be able to block a room for maintenance at any hour and for
   * any length, which is exactly what those limits forbid.
   */
  public function testInternalBookingsIgnoreDurationLimits(): void {
    $this->setBookingConfig(['max_duration_minutes' => 60, 'granularity_minutes' => 30]);
    $space = $this->createSpace();

    $this->assertValid(
      $this->buildBooking([$space], '+1 Tuesday 08:07', '+1 Tuesday 18:43', [
        'booking_type' => BookingType::Unavailable->value,
      ]),
      'A closure should not be held to member booking limits.',
    );
  }

  /**
   * Bookings in the past are refused.
   */
  public function testPastBookingsAreRefused(): void {
    $space = $this->createSpace();

    $this->assertInvalid(
      $this->buildBooking([$space], '-1 Tuesday 10:00', '-1 Tuesday 11:00'),
      'in the past',
    );
  }

  /**
   * Bookings beyond the advance limit are refused.
   */
  public function testMaximumAdvanceWindow(): void {
    $this->setBookingConfig(['max_advance_days' => 7]);
    $space = $this->createSpace();

    $this->assertInvalid(
      $this->buildBooking([$space], '+30 days 10:00', '+30 days 11:00'),
      'up to',
    );
    $this->assertValid($this->buildBooking([$space], '+2 days 10:00', '+2 days 11:00'));
  }

  /**
   * Bookings must respect the minimum notice.
   */
  public function testMinimumNotice(): void {
    $this->setBookingConfig(['min_advance_minutes' => 1440]);
    $space = $this->createSpace();

    $this->assertInvalid(
      $this->buildBooking([$space], '+2 hours', '+3 hours'),
      'in advance',
    );
  }

  /**
   * Bookings outside opening hours are refused.
   */
  public function testOutsideOpeningHours(): void {
    $hours = [];
    foreach (range(1, 7) as $weekday) {
      $hours[$weekday] = ['closed' => FALSE, 'open' => 540, 'close' => 1020];
    }
    $this->setBookingConfig(['opening_hours' => $hours]);
    $space = $this->createSpace();

    $this->assertInvalid(
      $this->buildBooking([$space], '+1 Tuesday 08:00', '+1 Tuesday 09:30'),
      'open from',
    );
    $this->assertValid($this->buildBooking([$space], '+1 Tuesday 09:00', '+1 Tuesday 10:00'));
  }

  /**
   * Bookings on a closed day are refused.
   */
  public function testClosedDay(): void {
    $hours = [];
    foreach (range(1, 7) as $weekday) {
      $hours[$weekday] = ['closed' => $weekday === 7, 'open' => 0, 'close' => 1440];
    }
    $this->setBookingConfig(['opening_hours' => $hours]);
    $space = $this->createSpace();

    $this->assertInvalid(
      $this->buildBooking([$space], '+1 Sunday 10:00', '+1 Sunday 11:00'),
      'closed',
    );
  }

  /**
   * A booking may not run past midnight.
   */
  public function testOvernightBookingsAreRefused(): void {
    $space = $this->createSpace();

    $this->assertInvalid(
      $this->buildBooking([$space], '+1 Tuesday 23:00', '+2 Wednesday 01:00'),
      'past midnight',
    );
  }

  /**
   * The weekly allowance caps how much one person may hold.
   */
  public function testWeeklyQuota(): void {
    $this->setBookingConfig(['quota_minutes_per_week' => 120]);
    $user = $this->createUser();
    $space = $this->createSpace();

    $this->createBooking([$space], '+1 Tuesday 10:00', '+1 Tuesday 11:30', [
      'holder' => $user->id(),
    ]);

    $this->assertInvalid(
      $this->buildBooking([$space], '+1 Tuesday 14:00', '+1 Tuesday 15:00', [
        'holder' => $user->id(),
      ]),
      'weekly allowance',
    );
  }

  /**
   * The allowance is per person, not shared.
   */
  public function testQuotaIsPerUser(): void {
    $this->setBookingConfig(['quota_minutes_per_week' => 120]);
    $alice = $this->createUser();
    $bob = $this->createUser();
    $space = $this->createSpace();

    $this->createBooking([$space], '+1 Tuesday 10:00', '+1 Tuesday 12:00', [
      'holder' => $alice->id(),
    ]);

    $this->assertValid(
      $this->buildBooking([$space], '+1 Tuesday 14:00', '+1 Tuesday 15:00', ['holder' => $bob->id()]),
      "One person's bookings must not consume another's allowance.",
    );
  }

}
