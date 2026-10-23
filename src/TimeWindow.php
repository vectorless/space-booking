<?php

declare(strict_types=1);

namespace Drupal\space_booking;

/**
 * A start and end expressed in a venue's local time.
 *
 * Opening hours and weekly quotas are human ideas: "Tuesday", "this week",
 * "half past nine". Timestamps are not. This class is the one place that
 * converts between the two, so daylight saving is handled once rather than
 * re-derived — and wrongly — in every rule.
 */
final readonly class TimeWindow {

  private function __construct(
    public int $start,
    public int $end,
    private \DateTimeImmutable $startLocal,
    private \DateTimeImmutable $endLocal,
  ) {}

  /**
   * Build a window from two timestamps, interpreted in the given timezone.
   *
   * @param int $start
   *   Start timestamp.
   * @param int $end
   *   End timestamp, exclusive.
   * @param string $timezone
   *   A PHP timezone identifier.
   */
  public static function fromTimestamps(int $start, int $end, string $timezone): self {
    $tz = new \DateTimeZone($timezone !== '' ? $timezone : date_default_timezone_get());
    return new self(
      $start,
      $end,
      (new \DateTimeImmutable('@' . $start))->setTimezone($tz),
      (new \DateTimeImmutable('@' . $end))->setTimezone($tz),
    );
  }

  /**
   * Whether start and end fall on the same calendar day.
   *
   * A booking that runs to midnight ends on the next calendar day by the
   * clock, but everyone involved would call that "until the end of Tuesday",
   * so it counts as the same day.
   */
  public function isSameDay(): bool {
    if ($this->startLocal->format('Y-m-d') === $this->endLocal->format('Y-m-d')) {
      return TRUE;
    }
    return $this->endLocal->format('H:i:s') === '00:00:00'
      && $this->endLocal->modify('-1 second')->format('Y-m-d') === $this->startLocal->format('Y-m-d');
  }

  /**
   * ISO-8601 weekday of the start: 1 for Monday through 7 for Sunday.
   */
  public function weekday(): int {
    return (int) $this->startLocal->format('N');
  }

  /**
   * Localised day name of the start.
   */
  public function dayName(): string {
    return $this->startLocal->format('l');
  }

  /**
   * Minutes from local midnight to the start.
   */
  public function startMinutes(): int {
    return ((int) $this->startLocal->format('G')) * 60 + (int) $this->startLocal->format('i');
  }

  /**
   * Minutes from local midnight to the end.
   *
   * Midnight at the end of the window reads as 1440, not 0, so that a
   * comparison against a closing time still makes sense.
   */
  public function endMinutes(): int {
    $minutes = ((int) $this->endLocal->format('G')) * 60 + (int) $this->endLocal->format('i');
    if ($minutes === 0 && $this->end > $this->start) {
      return 1440;
    }
    return $minutes;
  }

  /**
   * Length of the window in seconds.
   */
  public function duration(): int {
    return max(0, $this->end - $this->start);
  }

  /**
   * Format minutes-since-midnight as a 24-hour clock time.
   *
   * @param int $minutes
   *   Minutes since midnight.
   */
  public static function formatMinutes(int $minutes): string {
    return sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
  }

  /**
   * First and last timestamp of the local week containing a moment.
   *
   * Weeks start on Monday, matching ISO-8601 and the first-day-of-week most
   * venues use on a wall planner.
   *
   * @param int $timestamp
   *   Any moment inside the week.
   * @param string $timezone
   *   A PHP timezone identifier.
   *
   * @return array{int, int}
   *   Week start timestamp and week end timestamp, exclusive.
   */
  public static function weekBounds(int $timestamp, string $timezone): array {
    $tz = new \DateTimeZone($timezone !== '' ? $timezone : date_default_timezone_get());
    $local = (new \DateTimeImmutable('@' . $timestamp))->setTimezone($tz);
    $start = $local->modify('monday this week')->setTime(0, 0);
    $end = $start->modify('+7 days');
    return [$start->getTimestamp(), $end->getTimestamp()];
  }

}
