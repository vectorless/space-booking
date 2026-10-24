<?php

declare(strict_types=1);

namespace Drupal\space_booking;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityChangedInterface;
use Drupal\space_booking\Enum\BookingStatus;
use Drupal\space_booking\Enum\BookingType;
use Drupal\user\EntityOwnerInterface;
use Drupal\user\UserInterface;

/**
 * A reservation of one or more spaces for a period of time.
 */
interface BookingInterface extends ContentEntityInterface, EntityChangedInterface, EntityOwnerInterface {

  /**
   * Start of the reservation, as a UNIX timestamp.
   */
  public function getStart(): int;

  /**
   * End of the reservation, as a UNIX timestamp. Exclusive.
   *
   * A booking ending at 10:00 and one starting at 10:00 do not overlap.
   */
  public function getEnd(): int;

  /**
   * Length of the reservation in seconds.
   */
  public function getDuration(): int;

  /**
   * The lifecycle state.
   */
  public function getStatus(): BookingStatus;

  /**
   * Move the booking to a new lifecycle state.
   */
  public function setStatus(BookingStatus $status): static;

  /**
   * What this booking represents.
   */
  public function getType(): BookingType;

  /**
   * Space IDs this booking occupies.
   *
   * @return list<int>
   *   The space IDs.
   */
  public function getSpaceIds(): array;

  /**
   * The spaces this booking occupies.
   *
   * @return array<int, \Drupal\space_booking\SpaceInterface>
   *   Spaces keyed by ID.
   */
  public function getSpaces(): array;

  /**
   * The person the booking is for, which is not always who created it.
   */
  public function getHolder(): ?UserInterface;

  /**
   * What this booking is for: the activity, class or event being held.
   *
   * NULL when the site does not link bookings to content, or when this
   * booking is a plain room reservation with nothing behind it.
   */
  public function getSubject(): ?EntityInterface;

  /**
   * Whether this booking currently occupies its spaces.
   */
  public function occupiesSpace(): bool;

  /**
   * Whether this booking's time range overlaps another range.
   *
   * @param int $start
   *   Range start timestamp.
   * @param int $end
   *   Range end timestamp, exclusive.
   */
  public function overlaps(int $start, int $end): bool;

}
