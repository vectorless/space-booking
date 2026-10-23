<?php

declare(strict_types=1);

namespace Drupal\space_booking;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityChangedInterface;
use Drupal\user\EntityOwnerInterface;

/**
 * A bookable resource: a room, hall, studio, court or desk.
 */
interface SpaceInterface extends ContentEntityInterface, EntityChangedInterface, EntityOwnerInterface {

  /**
   * Whether the space is open for reservations.
   *
   * An unbookable space still appears on the schedule — that is the difference
   * between "assigned" and "bookable". It shows who holds it without offering
   * the slot to anyone else.
   */
  public function isBookable(): bool;

  /**
   * Whether bookings here must be approved before they are confirmed.
   */
  public function requiresApproval(): bool;

  /**
   * Maximum number of people, or NULL when unlimited.
   */
  public function getCapacity(): ?int;

  /**
   * Taxonomy term IDs tagging this space.
   *
   * @return list<int>
   *   The term IDs.
   */
  public function getTagIds(): array;

  /**
   * Spaces that cannot be used at the same time as this one.
   *
   * A hall that divides into two halves is three spaces, not one: booking the
   * whole hall has to block both halves, and vice versa.
   *
   * @return list<int>
   *   Space IDs that conflict with this one.
   */
  public function getConflictingSpaceIds(): array;

}
