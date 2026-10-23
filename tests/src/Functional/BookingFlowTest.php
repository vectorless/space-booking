<?php

declare(strict_types=1);

namespace Drupal\Tests\space_booking\Functional;

use Drupal\Tests\BrowserTestBase;
use Drupal\space_booking\Enum\BookingStatus;
use Drupal\space_booking\SpaceInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Booking a space the way a visitor actually does it.
 */
#[Group('space_booking')]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(FALSE)]
final class BookingFlowTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['space_booking'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->config('system.date')->set('timezone.default', 'Europe/London')->save();

    // Open every day so the fixture times are never the reason a test fails.
    $hours = [];
    foreach (range(1, 7) as $weekday) {
      $hours[$weekday] = ['closed' => FALSE, 'open' => 0, 'close' => 1440];
    }
    $this->config('space_booking.settings')
      ->set('opening_hours', $hours)
      ->set('min_duration_minutes', 0)
      ->set('max_advance_days', 0)
      ->save();
  }

  /**
   * Create a space.
   *
   * @param string $label
   *   The space name.
   * @param array<string, mixed> $values
   *   Field values to override.
   */
  private function createSpace(string $label, array $values = []): SpaceInterface {
    $space = \Drupal::entityTypeManager()->getStorage('space_booking_space')->create($values + [
      'label' => $label,
      'status' => TRUE,
      'bookable' => TRUE,
    ]);
    $space->save();
    \assert($space instanceof SpaceInterface);
    return $space;
  }

  /**
   * Submit the booking form for a space.
   */
  private function submitBooking(SpaceInterface $space, string $date, string $from, string $to): void {
    $this->drupalGet('/bookings/book');
    $this->submitForm([
      'spaces[target_id]' => sprintf('%s (%s)', $space->label(), $space->id()),
      'start[0][value][date]' => $date,
      'start[0][value][time]' => $from,
      'end[0][value][date]' => $date,
      'end[0][value][time]' => $to,
    ], 'Save');
  }

  /**
   * A date a few days out, in the venue's timezone.
   */
  private function soon(): string {
    return (new \DateTimeImmutable('+3 days', new \DateTimeZone('Europe/London')))->format('Y-m-d');
  }

  /**
   * The schedule is closed to people without permission.
   */
  public function testScheduleRequiresPermission(): void {
    $this->drupalGet('/bookings');
    $this->assertSession()->statusCodeEquals(403);
  }

  /**
   * Someone who may look can see the grid and its spaces.
   */
  public function testScheduleShowsSpaces(): void {
    $this->createSpace('Main Hall');
    $this->createSpace('Quiet Room');

    $this->drupalLogin($this->drupalCreateUser(['view space schedule']));
    $this->drupalGet('/bookings');

    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Main Hall');
    $this->assertSession()->pageTextContains('Quiet Room');
  }

  /**
   * Looking is not booking.
   */
  public function testViewingDoesNotGrantBooking(): void {
    $this->drupalLogin($this->drupalCreateUser(['view space schedule']));

    $this->drupalGet('/bookings/book');
    $this->assertSession()->statusCodeEquals(403);
  }

  /**
   * A member books a free space and it is confirmed.
   */
  public function testMemberCanBookFreeSpace(): void {
    $space = $this->createSpace('Main Hall');
    $this->drupalLogin($this->drupalCreateUser(['view space schedule', 'create space booking']));

    $this->submitBooking($space, $this->soon(), '10:00:00', '11:00:00');

    $this->assertSession()->pageTextContains('Your booking is confirmed.');

    $bookings = \Drupal::entityTypeManager()->getStorage('space_booking_booking')->loadMultiple();
    $this->assertCount(1, $bookings);
    $booking = reset($bookings);
    $this->assertSame(BookingStatus::Confirmed, $booking->getStatus());
  }

  /**
   * The booking's holder defaults to whoever made it.
   */
  public function testHolderDefaultsToTheBooker(): void {
    $space = $this->createSpace('Main Hall');
    $user = $this->drupalCreateUser(['view space schedule', 'create space booking']);
    $this->drupalLogin($user);

    $this->submitBooking($space, $this->soon(), '10:00:00', '11:00:00');

    $bookings = \Drupal::entityTypeManager()->getStorage('space_booking_booking')->loadMultiple();
    $booking = reset($bookings);
    $this->assertSame((int) $user->id(), (int) $booking->getHolder()?->id());
  }

  /**
   * A clash is refused with an explanation rather than silently accepted.
   */
  public function testDoubleBookingIsRefused(): void {
    $space = $this->createSpace('Main Hall');
    $this->drupalLogin($this->drupalCreateUser(['view space schedule', 'create space booking']));

    $this->submitBooking($space, $this->soon(), '10:00:00', '11:00:00');
    $this->assertSession()->pageTextContains('Your booking is confirmed.');

    $this->submitBooking($space, $this->soon(), '10:30:00', '11:30:00');

    $this->assertSession()->pageTextContains('already booked');
    $this->assertCount(
      1,
      \Drupal::entityTypeManager()->getStorage('space_booking_booking')->loadMultiple(),
      'The clashing booking must not have been saved.',
    );
  }

  /**
   * A space that needs approval produces a request, not a confirmation.
   */
  public function testApprovalSpaceCreatesRequest(): void {
    $space = $this->createSpace('Main Hall', ['requires_approval' => TRUE]);
    $this->drupalLogin($this->drupalCreateUser(['view space schedule', 'create space booking']));

    $this->submitBooking($space, $this->soon(), '10:00:00', '11:00:00');

    $this->assertSession()->pageTextContains('Your request has been sent.');

    $bookings = \Drupal::entityTypeManager()->getStorage('space_booking_booking')->loadMultiple();
    $booking = reset($bookings);
    $this->assertSame(BookingStatus::Pending, $booking->getStatus());
  }

  /**
   * An administrator approves a request from the queue.
   */
  public function testAdministratorApprovesRequest(): void {
    $space = $this->createSpace('Main Hall', ['requires_approval' => TRUE]);
    $member = $this->drupalCreateUser(['view space schedule', 'create space booking']);
    $this->drupalLogin($member);
    $this->submitBooking($space, $this->soon(), '10:00:00', '11:00:00');

    $bookings = \Drupal::entityTypeManager()->getStorage('space_booking_booking')->loadMultiple();
    $booking = reset($bookings);

    $this->drupalLogin($this->drupalCreateUser(['administer space booking', 'approve space bookings']));
    $this->drupalGet('/admin/content/bookings/approvals');
    $this->assertSession()->pageTextContains('Main Hall');

    $this->drupalGet('/admin/content/bookings/' . $booking->id() . '/approve');
    $this->submitForm([], 'Confirm');

    $this->assertSession()->pageTextContains('Booking approved.');

    $storage = \Drupal::entityTypeManager()->getStorage('space_booking_booking');
    $storage->resetCache();
    $this->assertSame(BookingStatus::Confirmed, $storage->load($booking->id())->getStatus());
  }

  /**
   * Rejecting a request frees the slot for somebody else.
   */
  public function testRejectingFreesTheSlot(): void {
    $space = $this->createSpace('Main Hall', ['requires_approval' => TRUE]);
    $member = $this->drupalCreateUser(['view space schedule', 'create space booking']);
    $this->drupalLogin($member);
    $this->submitBooking($space, $this->soon(), '10:00:00', '11:00:00');

    $bookings = \Drupal::entityTypeManager()->getStorage('space_booking_booking')->loadMultiple();
    $booking = reset($bookings);

    $this->drupalLogin($this->drupalCreateUser(['administer space booking', 'approve space bookings']));
    $this->drupalGet('/admin/content/bookings/' . $booking->id() . '/reject');
    $this->submitForm([], 'Confirm');
    $this->assertSession()->pageTextContains('Booking rejected.');

    $this->assertTrue(
      \Drupal::service('space_booking.availability')->isAvailable(
        [(int) $space->id()],
        (new \DateTimeImmutable($this->soon() . ' 10:00', new \DateTimeZone('Europe/London')))->getTimestamp(),
        (new \DateTimeImmutable($this->soon() . ' 11:00', new \DateTimeZone('Europe/London')))->getTimestamp(),
      ),
      'The slot should be free again after a rejection.',
    );
  }

  /**
   * The approval queue is not open to ordinary members.
   */
  public function testApprovalQueueIsRestricted(): void {
    $this->drupalLogin($this->drupalCreateUser(['view space schedule', 'create space booking']));

    $this->drupalGet('/admin/content/bookings/approvals');
    $this->assertSession()->statusCodeEquals(403);
  }

  /**
   * With booking switched off the form refuses to take anything.
   */
  public function testKillSwitchClosesBooking(): void {
    $this->createSpace('Main Hall');
    $this->config('space_booking.settings')->set('enabled', FALSE)->save();

    $this->drupalLogin($this->drupalCreateUser(['view space schedule', 'create space booking']));
    $this->drupalGet('/bookings/book');

    $this->assertSession()->pageTextContains('Booking is currently closed.');
  }

  /**
   * The schedule still works when booking is closed.
   */
  public function testScheduleSurvivesTheKillSwitch(): void {
    $this->createSpace('Main Hall');
    $this->config('space_booking.settings')->set('enabled', FALSE)->save();

    $this->drupalLogin($this->drupalCreateUser(['view space schedule']));
    $this->drupalGet('/bookings');

    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Main Hall');
  }

  /**
   * The JSON feed describes spaces and bookings.
   */
  public function testJsonFeed(): void {
    $space = $this->createSpace('Main Hall');
    $this->drupalLogin($this->drupalCreateUser(['view space schedule', 'create space booking']));
    $this->submitBooking($space, $this->soon(), '10:00:00', '11:00:00');

    $this->drupalGet('/bookings/data');
    $payload = json_decode($this->getSession()->getPage()->getContent(), TRUE, 512, JSON_THROW_ON_ERROR);

    $this->assertSame('Main Hall', $payload['spaces'][0]['label']);
    $this->assertCount(1, $payload['bookings']);
    $this->assertSame('confirmed', $payload['bookings'][0]['status']);
  }

}
