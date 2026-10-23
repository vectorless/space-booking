<?php

declare(strict_types=1);

namespace Drupal\space_booking\Enum;

use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * What a booking row actually represents.
 *
 * A booking is not only "a person reserved a room". The same row is also how a
 * space gets blocked for maintenance or marked unavailable, which keeps one
 * overlap engine authoritative instead of two competing ones.
 */
enum BookingType: string {

  // A reservation held by a user.
  case User = 'user';

  // The venue's own use: occupies the space, but is not a public reservation.
  case Internal = 'internal';

  // The space cannot be used at all, for maintenance or a closure.
  case Unavailable = 'unavailable';

  /**
   * Human-readable label.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup
   *   The label.
   */
  public function label(): TranslatableMarkup {
    return match ($this) {
      self::User => new TranslatableMarkup('User booking'),
      self::Internal => new TranslatableMarkup('Internal use'),
      self::Unavailable => new TranslatableMarkup('Unavailable'),
    };
  }

  /**
   * Whether this type is created by ordinary users rather than staff.
   *
   * Rules written for members, such as maximum length or opening hours, are
   * applied only to public bookings. Staff must be able to record a closure
   * at any hour and for any length.
   *
   * @return bool
   *   TRUE for a public reservation.
   */
  public function isPublic(): bool {
    return $this === self::User;
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
