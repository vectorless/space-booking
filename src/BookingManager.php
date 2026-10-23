<?php

declare(strict_types=1);

namespace Drupal\space_booking;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\space_booking\Enum\BookingStatus;

/**
 * Booking lifecycle: what status a new booking gets, and who hears about it.
 */
final class BookingManager {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly AccountProxyInterface $currentUser,
    private readonly TimeInterface $time,
    private readonly MailManagerInterface $mailManager,
    private readonly LanguageManagerInterface $languageManager,
  ) {}

  /**
   * Whether booking is switched on at all.
   *
   * The kill switch. With this off the schedule still renders, so the venue
   * keeps its public timetable even while reservations are closed.
   */
  public function enabled(): bool {
    return BookingSettings::fromConfig($this->configFactory)->enabled;
  }

  /**
   * The status a booking should take when first saved.
   *
   * Approval is a property of the space, so a venue can leave most rooms
   * self-service and gate only the ones that need a conversation first.
   */
  public function initialStatus(BookingInterface $booking): BookingStatus {
    if (!$booking->getType()->isPublic()) {
      return BookingStatus::Confirmed;
    }
    foreach ($booking->getSpaces() as $space) {
      \assert($space instanceof SpaceInterface);
      if ($space->requiresApproval()) {
        return BookingStatus::Pending;
      }
    }
    return BookingStatus::Confirmed;
  }

  /**
   * Approve a pending booking.
   *
   * Re-checks availability first: an administrator may be approving a request
   * that a later confirmed booking has already overtaken.
   *
   * @return bool
   *   TRUE when approved, FALSE when the slot is no longer free.
   */
  public function approve(BookingInterface $booking): bool {
    $availability = new AvailabilityChecker($this->entityTypeManager, $this->configFactory);
    if (!$availability->isAvailable($booking->getSpaceIds(), $booking->getStart(), $booking->getEnd(), (int) $booking->id())) {
      return FALSE;
    }
    $booking->setStatus(BookingStatus::Confirmed)->save();
    $this->notify($booking, 'approved');
    return TRUE;
  }

  /**
   * Refuse a pending booking, releasing the slot.
   */
  public function reject(BookingInterface $booking): void {
    $booking->setStatus(BookingStatus::Rejected)->save();
    $this->notify($booking, 'rejected');
  }

  /**
   * Cancel a booking, releasing the slot.
   */
  public function cancel(BookingInterface $booking): void {
    $booking->setStatus(BookingStatus::Cancelled)->save();
    $this->notify($booking, 'cancelled');
  }

  /**
   * Pending bookings awaiting a decision, oldest first.
   *
   * @return array<int, \Drupal\space_booking\BookingInterface>
   *   Bookings keyed by ID.
   */
  public function pending(): array {
    $storage = $this->entityTypeManager->getStorage('space_booking_booking');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('status', BookingStatus::Pending->value)
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
   * Send the configured notifications for a lifecycle change.
   *
   * @param \Drupal\space_booking\BookingInterface $booking
   *   The booking that changed.
   * @param string $key
   *   One of created, approved, rejected or cancelled.
   */
  public function notify(BookingInterface $booking, string $key): void {
    $config = $this->configFactory->get(BookingSettings::CONFIG_NAME);
    $langcode = $this->languageManager->getDefaultLanguage()->getId();
    $params = ['booking' => $booking, 'key' => $key];

    if ((bool) $config->get('notify_holder')) {
      $holder = $booking->getHolder();
      $email = $holder?->getEmail();
      if ($email !== NULL && $email !== '') {
        $this->mailManager->mail('space_booking', 'holder_' . $key, $email, $langcode, $params);
      }
    }

    if ((bool) $config->get('notify_venue')) {
      $venue = (string) $config->get('venue_email');
      if ($venue !== '') {
        $this->mailManager->mail('space_booking', 'venue_' . $key, $venue, $langcode, $params);
      }
    }
  }

  /**
   * The current request time, exposed so forms share one clock.
   */
  public function now(): int {
    return $this->time->getRequestTime();
  }

  /**
   * The acting user.
   */
  public function currentUserId(): int {
    return (int) $this->currentUser->id();
  }

}
