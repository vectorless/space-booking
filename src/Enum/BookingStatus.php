<?php

declare(strict_types=1);

namespace Drupal\space_booking\Enum;

use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Where a booking sits in the approval lifecycle.
 */
enum BookingStatus: string {

  // Awaiting a decision; the space is held but not confirmed.
  case Pending = 'pending';

  // Confirmed and occupying the space.
  case Confirmed = 'confirmed';

  // Withdrawn by the holder or an administrator.
  case Cancelled = 'cancelled';

  // Refused by an administrator.
  case Rejected = 'rejected';

  /**
   * Human-readable label.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup
   *   The label.
   */
  public function label(): TranslatableMarkup {
    return match ($this) {
      self::Pending => new TranslatableMarkup('Pending approval'),
      self::Confirmed => new TranslatableMarkup('Confirmed'),
      self::Cancelled => new TranslatableMarkup('Cancelled'),
      self::Rejected => new TranslatableMarkup('Rejected'),
    };
  }

  /**
   * Whether a booking in this state occupies its spaces.
   *
   * Pending bookings hold the slot: releasing it while an administrator
   * decides would let a second person book the same hour and create a
   * conflict that only surfaces at approval time.
   *
   * @return bool
   *   TRUE when the booking blocks its spaces.
   */
  public function occupiesSpace(): bool {
    return match ($this) {
      self::Pending, self::Confirmed => TRUE,
      self::Cancelled, self::Rejected => FALSE,
    };
  }

  /**
   * Statuses that occupy a space, as raw values for queries.
   *
   * @return list<string>
   *   The status values.
   */
  public static function occupyingValues(): array {
    return array_values(array_map(
      static fn (self $case): string => $case->value,
      array_filter(self::cases(), static fn (self $case): bool => $case->occupiesSpace()),
    ));
  }

  /**
   * Options suitable for a form element.
   *
   * @return array<string, \Drupal\Core\StringTranslation\TranslatableMarkup>
   *   Labels keyed by value.
   */
  public static function options(): array {
    $options = [];
    foreach (self::cases() as $case) {
      $options[$case->value] = $case->label();
    }
    return $options;
  }

}
