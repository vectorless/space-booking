<?php

declare(strict_types=1);

namespace Drupal\Tests\space_booking\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\space_booking\Traits\SpaceBookingTestTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Who may see and change a booking.
 *
 * A booking carries somebody's name and plans. The schedule can say an hour
 * is taken without saying who took it, and these tests pin that down.
 */
#[Group('space_booking')]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(FALSE)]
final class BookingAccessTest extends KernelTestBase {

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

    // User 1 is a superuser and would mask real permission failures.
    $this->createUser();
  }

  /**
   * The holder can see their own booking.
   */
  public function testHolderCanViewOwnBooking(): void {
    $holder = $this->createUser();
    $space = $this->createSpace();
    $booking = $this->createBooking([$space], '+1 Tuesday 10:00', '+1 Tuesday 11:00', [
      'holder' => $holder->id(),
    ]);

    $this->assertTrue($booking->access('view', $holder));
  }

  /**
   * Somebody else's booking is private by default.
   */
  public function testStrangerCannotViewAnothersBooking(): void {
    $holder = $this->createUser();
    $stranger = $this->createUser(['view space schedule']);
    $space = $this->createSpace();
    $booking = $this->createBooking([$space], '+1 Tuesday 10:00', '+1 Tuesday 11:00', [
      'holder' => $holder->id(),
    ]);

    $this->assertFalse(
      $booking->access('view', $stranger),
      'Seeing the schedule must not reveal who holds each booking.',
    );
  }

  /**
   * The explicit permission opens other people's bookings.
   */
  public function testViewAnyPermission(): void {
    $holder = $this->createUser();
    $nosy = $this->createUser(['view any space booking']);
    $space = $this->createSpace();
    $booking = $this->createBooking([$space], '+1 Tuesday 10:00', '+1 Tuesday 11:00', [
      'holder' => $holder->id(),
    ]);

    $this->assertTrue($booking->access('view', $nosy));
  }

  /**
   * Editing your own booking needs the permission as well as ownership.
   */
  public function testEditingOwnBookingNeedsPermission(): void {
    $withPermission = $this->createUser(['edit own space bookings']);
    $withoutPermission = $this->createUser();
    $space = $this->createSpace();

    $mine = $this->createBooking([$space], '+1 Tuesday 10:00', '+1 Tuesday 11:00', [
      'holder' => $withPermission->id(),
    ]);
    $theirs = $this->createBooking([$space], '+1 Tuesday 12:00', '+1 Tuesday 13:00', [
      'holder' => $withoutPermission->id(),
    ]);

    $this->assertTrue($mine->access('update', $withPermission));
    $this->assertFalse($theirs->access('update', $withoutPermission));
  }

  /**
   * Ownership alone does not let you edit somebody else's booking.
   */
  public function testCannotEditAnothersBooking(): void {
    $holder = $this->createUser(['edit own space bookings']);
    $other = $this->createUser(['edit own space bookings']);
    $space = $this->createSpace();
    $booking = $this->createBooking([$space], '+1 Tuesday 10:00', '+1 Tuesday 11:00', [
      'holder' => $holder->id(),
    ]);

    $this->assertFalse($booking->access('update', $other));
  }

  /**
   * Cancelling your own booking needs its own permission.
   */
  public function testDeleteOwnBooking(): void {
    $holder = $this->createUser(['delete own space bookings']);
    $space = $this->createSpace();
    $booking = $this->createBooking([$space], '+1 Tuesday 10:00', '+1 Tuesday 11:00', [
      'holder' => $holder->id(),
    ]);

    $this->assertTrue($booking->access('delete', $holder));
  }

  /**
   * An administrator may do anything.
   */
  public function testAdministratorHasFullAccess(): void {
    $admin = $this->createUser(['administer space booking']);
    $holder = $this->createUser();
    $space = $this->createSpace();
    $booking = $this->createBooking([$space], '+1 Tuesday 10:00', '+1 Tuesday 11:00', [
      'holder' => $holder->id(),
    ]);

    foreach (['view', 'update', 'delete'] as $operation) {
      $this->assertTrue($booking->access($operation, $admin), "Admin should be able to $operation.");
    }
  }

  /**
   * Spaces are visible to anyone who may see the schedule.
   */
  public function testSpaceVisibility(): void {
    $viewer = $this->createUser(['view space schedule']);
    $nobody = $this->createUser();
    $space = $this->createSpace();

    $this->assertTrue($space->access('view', $viewer));
    $this->assertFalse($space->access('view', $nobody));
    $this->assertFalse($space->access('update', $viewer), 'Seeing a space is not editing it.');
  }

}
