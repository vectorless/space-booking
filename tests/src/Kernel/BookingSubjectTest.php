<?php

declare(strict_types=1);

namespace Drupal\Tests\space_booking\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\space_booking\Traits\SpaceBookingTestTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\node\Entity\NodeType;
use Drupal\node\NodeInterface;
use Drupal\space_booking\Enum\BookingType;
use Drupal\space_booking\Enum\BookingStatus;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Linking a booking to the activity it holds.
 *
 * The venue owns the room and the hour; whoever runs the activity owns the
 * words describing it. These tests pin down that an activity can show its own
 * dates without storing them, so the two can never contradict each other.
 */
#[Group('space_booking')]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(FALSE)]
final class BookingSubjectTest extends KernelTestBase {

  use SpaceBookingTestTrait;
  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system', 'user', 'field', 'options', 'datetime', 'text', 'filter',
    'taxonomy', 'node', 'space_booking',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('taxonomy_term');
    // Config first: the booking's "for" field is defined from it, and the
    // schema has to be built with the field already known.
    $this->installConfig(['space_booking', 'node', 'field']);
    $this->installEntitySchema('space_booking_space');
    $this->installEntitySchema('space_booking_booking');
    // Saving a node touches node_access, which kernel tests do not install.
    $this->installSchema('node', ['node_access']);

    NodeType::create(['type' => 'activity', 'name' => 'Activity'])->save();

    $this->config('system.date')->set('timezone.default', $this->venueTimezone)->save();
    $this->openAllHours();
    $this->setBookingConfig(['max_advance_days' => 0, 'min_duration_minutes' => 0]);
  }

  /**
   * Create an activity node.
   */
  private function activity(string $title): NodeInterface {
    $node = $this->container->get('entity_type.manager')->getStorage('node')->create([
      'type' => 'activity',
      'title' => $title,
      'status' => 1,
    ]);
    $node->save();
    return $node;
  }

  /**
   * A booking can point at the activity it holds.
   */
  public function testBookingCarriesItsSubject(): void {
    $yoga = $this->activity('Slow Yoga');
    $space = $this->createSpace();
    $booking = $this->createBooking([$space], '+1 Tuesday 10:00', '+1 Tuesday 11:00', [
      'subject' => $yoga->id(),
    ]);

    $this->assertSame((int) $yoga->id(), (int) $booking->getSubject()?->id());
  }

  /**
   * An activity finds its own dates without storing them.
   */
  public function testActivityFindsItsBookings(): void {
    $yoga = $this->activity('Slow Yoga');
    $choir = $this->activity('Community Singing');
    $space = $this->createSpace();

    $this->createBooking([$space], '+1 Tuesday 10:00', '+1 Tuesday 11:00', ['subject' => $yoga->id()]);
    $this->createBooking([$space], '+2 Wednesday 10:00', '+2 Wednesday 11:00', ['subject' => $yoga->id()]);
    $this->createBooking([$space], '+1 Tuesday 14:00', '+1 Tuesday 15:00', ['subject' => $choir->id()]);

    $found = $this->container->get('space_booking.availability')->bookingsForSubject($yoga);

    $this->assertCount(2, $found, 'Only this activity\'s own sessions.');
    foreach ($found as $booking) {
      $this->assertSame((int) $yoga->id(), (int) $booking->getSubject()?->id());
    }
  }

  /**
   * Dates come back soonest first, which is the order a listing wants.
   */
  public function testBookingsComeBackInOrder(): void {
    $yoga = $this->activity('Slow Yoga');
    $space = $this->createSpace();

    $later = $this->createBooking([$space], '+3 Thursday 10:00', '+3 Thursday 11:00', ['subject' => $yoga->id()]);
    $sooner = $this->createBooking([$space], '+1 Tuesday 10:00', '+1 Tuesday 11:00', ['subject' => $yoga->id()]);

    $this->assertSame(
      [(int) $sooner->id(), (int) $later->id()],
      array_keys($this->container->get('space_booking.availability')->bookingsForSubject($yoga)),
    );
  }

  /**
   * A cancelled session disappears from the activity's dates.
   */
  public function testCancelledSessionsAreNotListed(): void {
    $yoga = $this->activity('Slow Yoga');
    $space = $this->createSpace();
    $booking = $this->createBooking([$space], '+1 Tuesday 10:00', '+1 Tuesday 11:00', ['subject' => $yoga->id()]);

    $availability = $this->container->get('space_booking.availability');
    $this->assertCount(1, $availability->bookingsForSubject($yoga));

    $booking->setStatus(BookingStatus::Cancelled)->save();

    $this->assertCount(0, $availability->bookingsForSubject($yoga),
      'A cancelled session must not still appear on the activity page.');
  }

  /**
   * Past sessions can be excluded, for an "upcoming dates" listing.
   */
  public function testUpcomingOnly(): void {
    $yoga = $this->activity('Slow Yoga');
    $space = $this->createSpace();

    $this->createBooking([$space], '-2 Tuesday 10:00', '-2 Tuesday 11:00', [
      'subject' => $yoga->id(),
      // Past bookings are the venue's own record, not a member reservation.
      'booking_type' => BookingType::Internal->value,
    ]);
    $this->createBooking([$space], '+1 Tuesday 10:00', '+1 Tuesday 11:00', ['subject' => $yoga->id()]);

    $availability = $this->container->get('space_booking.availability');

    $this->assertCount(2, $availability->bookingsForSubject($yoga), 'All sessions, past included.');
    $this->assertCount(1, $availability->bookingsForSubject($yoga, $this->at('now')), 'Upcoming only.');
  }

  /**
   * The number of dates shown can be capped.
   */
  public function testLimit(): void {
    $yoga = $this->activity('Slow Yoga');
    $space = $this->createSpace();
    foreach (['+1 Tuesday', '+2 Wednesday', '+3 Thursday'] as $day) {
      $this->createBooking([$space], $day . ' 10:00', $day . ' 11:00', ['subject' => $yoga->id()]);
    }

    $this->assertCount(
      2,
      $this->container->get('space_booking.availability')->bookingsForSubject($yoga, NULL, 2),
    );
  }

  /**
   * A booking with nothing behind it is still perfectly valid.
   *
   * Someone hiring the hall for a party is not an activity with a page.
   */
  public function testBookingWithoutSubject(): void {
    $space = $this->createSpace();
    $booking = $this->createBooking([$space], '+1 Tuesday 10:00', '+1 Tuesday 11:00');

    $this->assertNull($booking->getSubject());
    $this->assertValid($booking);
  }

  /**
   * The booking borrows the activity's name when it has no title of its own.
   */
  public function testLabelFallsBackToTheActivity(): void {
    $yoga = $this->activity('Slow Yoga');
    $space = $this->createSpace();
    $booking = $this->createBooking([$space], '+1 Tuesday 10:00', '+1 Tuesday 11:00', [
      'subject' => $yoga->id(),
    ]);

    $this->assertSame('Slow Yoga', $booking->label());
  }

  /**
   * Renaming the activity renames what the schedule shows.
   *
   * The whole point of the link: the words are edited in one place.
   */
  public function testRenamingTheActivityChangesTheSchedule(): void {
    $yoga = $this->activity('Slow Yoga');
    $space = $this->createSpace();
    $booking = $this->createBooking([$space], '+1 Tuesday 10:00', '+1 Tuesday 11:00', [
      'subject' => $yoga->id(),
    ]);

    $yoga->setTitle('Slow Yoga (candlelit)')->save();

    $storage = $this->container->get('entity_type.manager')->getStorage('space_booking_booking');
    $storage->resetCache();
    $reloaded = $storage->load($booking->id());

    $this->assertSame('Slow Yoga (candlelit)', $reloaded->label(),
      'The provider edits the wording once and the schedule follows.');
  }

}
