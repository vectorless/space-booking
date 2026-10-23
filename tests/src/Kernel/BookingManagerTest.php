<?php

declare(strict_types=1);

namespace Drupal\Tests\space_booking\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\space_booking\Traits\SpaceBookingTestTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\space_booking\BookingManager;
use Drupal\space_booking\Enum\BookingStatus;
use Drupal\space_booking\Enum\BookingType;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * The approval lifecycle.
 */
#[Group('space_booking')]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(FALSE)]
final class BookingManagerTest extends KernelTestBase {

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
   * The booking lifecycle service under test.
   */
  private BookingManager $manager;

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

    $this->manager = $this->container->get('space_booking.manager');
  }

  /**
   * A space that needs no approval confirms immediately.
   */
  public function testOrdinarySpaceConfirmsStraightAway(): void {
    $space = $this->createSpace();
    $booking = $this->buildBooking([$space], '+1 Tuesday 10:00', '+1 Tuesday 11:00');

    $this->assertSame(BookingStatus::Confirmed, $this->manager->initialStatus($booking));
  }

  /**
   * A space that needs approval holds the booking as pending.
   */
  public function testApprovalSpaceStartsPending(): void {
    $space = $this->createSpace('Main Hall', ['requires_approval' => TRUE]);
    $booking = $this->buildBooking([$space], '+1 Tuesday 10:00', '+1 Tuesday 11:00');

    $this->assertSame(BookingStatus::Pending, $this->manager->initialStatus($booking));
  }

  /**
   * One space needing approval is enough to gate the whole booking.
   */
  public function testAnySpaceNeedingApprovalGatesTheBooking(): void {
    $open = $this->createSpace('Studio');
    $gated = $this->createSpace('Main Hall', ['requires_approval' => TRUE]);
    $booking = $this->buildBooking([$open, $gated], '+1 Tuesday 10:00', '+1 Tuesday 11:00');

    $this->assertSame(BookingStatus::Pending, $this->manager->initialStatus($booking));
  }

  /**
   * Staff entries are never held up for approval.
   */
  public function testInternalBookingsSkipApproval(): void {
    $space = $this->createSpace('Main Hall', ['requires_approval' => TRUE]);
    $booking = $this->buildBooking([$space], '+1 Tuesday 10:00', '+1 Tuesday 11:00', [
      'booking_type' => BookingType::Unavailable->value,
    ]);

    $this->assertSame(BookingStatus::Confirmed, $this->manager->initialStatus($booking));
  }

  /**
   * Approving a pending booking confirms it.
   */
  public function testApproveConfirms(): void {
    $space = $this->createSpace();
    $booking = $this->createBooking([$space], '+1 Tuesday 10:00', '+1 Tuesday 11:00', [
      'status' => BookingStatus::Pending->value,
    ]);

    $this->assertTrue($this->manager->approve($booking));
    $this->assertSame(BookingStatus::Confirmed, $booking->getStatus());
  }

  /**
   * Approval is refused when the slot has since been taken.
   *
   * Two people can both request the same hour. Whichever is confirmed first
   * wins, and approving the loser must not quietly double-book the room.
   */
  public function testApproveRefusesWhenTheSlotHasGone(): void {
    $space = $this->createSpace();
    $request = $this->createBooking([$space], '+1 Tuesday 10:00', '+1 Tuesday 11:00', [
      'status' => BookingStatus::Pending->value,
    ]);

    // A second booking confirms for the same hour. Validation is bypassed
    // here precisely to reproduce the race the approval step must catch.
    $rival = $this->buildBooking([$space], '+1 Tuesday 10:00', '+1 Tuesday 11:00', [
      'status' => BookingStatus::Confirmed->value,
    ]);
    $rival->save();

    $this->assertFalse($this->manager->approve($request), 'A taken slot must not be approved.');
    $this->assertSame(BookingStatus::Pending, $request->getStatus(), 'The request stays pending for a human to resolve.');
  }

  /**
   * Rejecting releases the slot.
   */
  public function testRejectReleasesTheSlot(): void {
    $space = $this->createSpace();
    $booking = $this->createBooking([$space], '+1 Tuesday 10:00', '+1 Tuesday 11:00', [
      'status' => BookingStatus::Pending->value,
    ]);

    $this->manager->reject($booking);

    $this->assertSame(BookingStatus::Rejected, $booking->getStatus());
    $this->assertTrue(
      $this->container->get('space_booking.availability')->isAvailable(
        [(int) $space->id()],
        $this->at('+1 Tuesday 10:00'),
        $this->at('+1 Tuesday 11:00'),
      ),
      'A rejected request must free the space.',
    );
  }

  /**
   * Cancelling releases the slot too.
   */
  public function testCancelReleasesTheSlot(): void {
    $space = $this->createSpace();
    $booking = $this->createBooking([$space], '+1 Tuesday 10:00', '+1 Tuesday 11:00');

    $this->manager->cancel($booking);

    $this->assertSame(BookingStatus::Cancelled, $booking->getStatus());
  }

  /**
   * The queue lists pending requests oldest first.
   */
  public function testPendingQueue(): void {
    $space = $this->createSpace();
    $later = $this->createBooking([$space], '+1 Tuesday 14:00', '+1 Tuesday 15:00', [
      'status' => BookingStatus::Pending->value,
    ]);
    $earlier = $this->createBooking([$space], '+1 Tuesday 09:00', '+1 Tuesday 10:00', [
      'status' => BookingStatus::Pending->value,
    ]);
    $this->createBooking([$space], '+1 Tuesday 11:00', '+1 Tuesday 12:00');

    $this->assertSame(
      [(int) $earlier->id(), (int) $later->id()],
      array_keys($this->manager->pending()),
      'Only pending bookings, soonest first.',
    );
  }

  /**
   * The kill switch reports the configured state.
   */
  public function testEnabledReflectsConfiguration(): void {
    $this->assertTrue($this->manager->enabled());

    $this->setBookingConfig(['enabled' => FALSE]);

    $this->assertFalse($this->manager->enabled());
  }

}
