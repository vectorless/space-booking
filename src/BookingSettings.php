<?php

declare(strict_types=1);

namespace Drupal\space_booking;

use Drupal\Core\Config\ConfigFactoryInterface;

/**
 * Typed, immutable view of the module's configuration.
 *
 * Reading settings through an object rather than poking at the config array
 * keeps the units honest: everything here is already converted to seconds or
 * to minutes-since-midnight, so callers never have to guess.
 */
final readonly class BookingSettings {

  public const string CONFIG_NAME = 'space_booking.settings';

  /**
   * Construct the settings value object.
   *
   * @param bool $enabled
   *   Master switch. When off, nothing can be booked.
   * @param int $granularity
   *   Slot size in seconds.
   * @param int $buffer
   *   Gap enforced either side of a booking, in seconds.
   * @param int $minDuration
   *   Shortest permitted booking, in seconds. Zero means no minimum.
   * @param int $maxDuration
   *   Longest permitted booking, in seconds. Zero means no maximum.
   * @param int $minAdvance
   *   How far ahead a booking must be made, in seconds. Zero means no limit.
   * @param int $maxAdvance
   *   How far ahead a booking may be made, in seconds. Zero means no limit.
   * @param int $quotaPerWeek
   *   Per-user weekly allowance, in seconds. Zero means unlimited.
   * @param string $subjectEntityType
   *   Entity type a booking may be "for" -- an activity, class or event on the
   *   host site. Empty disables the link entirely.
   * @param array<int, array{open: int, close: int}|null> $openingHours
   *   Opening hours keyed by ISO weekday (1 = Monday). Minutes since midnight.
   *   A NULL value means closed that day.
   */
  public function __construct(
    public bool $enabled = TRUE,
    public int $granularity = 900,
    public int $buffer = 0,
    public int $minDuration = 900,
    public int $maxDuration = 0,
    public int $minAdvance = 0,
    public int $maxAdvance = 0,
    public int $quotaPerWeek = 0,
    public string $subjectEntityType = 'node',
    public array $openingHours = [],
  ) {}

  /**
   * Build from the config factory.
   */
  public static function fromConfig(ConfigFactoryInterface $configFactory): self {
    $config = $configFactory->get(self::CONFIG_NAME);

    $hours = [];
    foreach (range(1, 7) as $weekday) {
      $day = $config->get('opening_hours.' . $weekday);
      $hours[$weekday] = is_array($day) && ($day['closed'] ?? FALSE) === FALSE
        ? ['open' => (int) ($day['open'] ?? 0), 'close' => (int) ($day['close'] ?? 1440)]
        : NULL;
    }

    return new self(
      enabled: (bool) ($config->get('enabled') ?? TRUE),
      granularity: (int) ($config->get('granularity_minutes') ?? 15) * 60,
      buffer: (int) ($config->get('buffer_minutes') ?? 0) * 60,
      minDuration: (int) ($config->get('min_duration_minutes') ?? 15) * 60,
      maxDuration: (int) ($config->get('max_duration_minutes') ?? 0) * 60,
      minAdvance: (int) ($config->get('min_advance_minutes') ?? 0) * 60,
      maxAdvance: (int) ($config->get('max_advance_days') ?? 0) * 86400,
      quotaPerWeek: (int) ($config->get('quota_minutes_per_week') ?? 0) * 60,
      subjectEntityType: $config->get('subject_entity_type') === NULL
        ? 'node'
        : (string) $config->get('subject_entity_type'),
      openingHours: $hours,
    );
  }

  /**
   * Opening hours for an ISO weekday, or NULL when closed.
   *
   * @param int $weekday
   *   ISO-8601 weekday, 1 (Monday) to 7 (Sunday).
   *
   * @return array{open: int, close: int}|null
   *   Minutes since midnight, or NULL when the venue is shut.
   */
  public function hoursFor(int $weekday): ?array {
    // An empty configuration means "no opening-hours restriction" rather than
    // "closed every day", otherwise a fresh install would refuse every booking.
    if ($this->openingHours === []) {
      return ['open' => 0, 'close' => 1440];
    }
    return $this->openingHours[$weekday] ?? NULL;
  }

}
