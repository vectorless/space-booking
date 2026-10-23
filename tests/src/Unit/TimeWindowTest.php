<?php

declare(strict_types=1);

namespace Drupal\Tests\space_booking\Unit;

use Drupal\space_booking\TimeWindow;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Local-time arithmetic, which is where booking bugs like to hide.
 */
#[CoversClass(TimeWindow::class)]
#[Group('space_booking')]
final class TimeWindowTest extends UnitTestCase {

  private const string TZ = 'Europe/London';

  /**
   * Builds a window from local wall-clock strings.
   */
  private function window(string $start, string $end, string $tz = self::TZ): TimeWindow {
    $zone = new \DateTimeZone($tz);
    return TimeWindow::fromTimestamps(
      (new \DateTimeImmutable($start, $zone))->getTimestamp(),
      (new \DateTimeImmutable($end, $zone))->getTimestamp(),
      $tz,
    );
  }

  /**
   * Minutes since midnight are read in the venue's timezone, not UTC.
   *
   * In summer London is an hour ahead of UTC, so a naive gmdate() would make
   * a 09:00 booking look like it starts at 08:00 and fall outside opening
   * hours.
   */
  public function testMinutesUseLocalTime(): void {
    $window = $this->window('2026-07-15 09:00', '2026-07-15 10:30');

    $this->assertSame(540, $window->startMinutes());
    $this->assertSame(630, $window->endMinutes());
  }

  /**
   * The same wall-clock time in winter gives the same minutes.
   */
  public function testMinutesAreStableAcrossDaylightSaving(): void {
    $summer = $this->window('2026-07-15 09:00', '2026-07-15 10:00');
    $winter = $this->window('2026-01-15 09:00', '2026-01-15 10:00');

    $this->assertSame($summer->startMinutes(), $winter->startMinutes());
    $this->assertSame($summer->endMinutes(), $winter->endMinutes());
  }

  /**
   * A booking inside one day is the same day.
   */
  public function testSameDay(): void {
    $this->assertTrue($this->window('2026-03-04 09:00', '2026-03-04 17:00')->isSameDay());
  }

  /**
   * Running to midnight still counts as the day it started on.
   */
  public function testEndingAtMidnightIsStillTheSameDay(): void {
    $window = $this->window('2026-03-04 22:00', '2026-03-05 00:00');

    $this->assertTrue($window->isSameDay());
    $this->assertSame(1440, $window->endMinutes(), 'Midnight reads as 1440 so it can be compared with a closing time.');
  }

  /**
   * Crossing midnight into the next day does not.
   */
  public function testCrossingMidnightIsNotTheSameDay(): void {
    $this->assertFalse($this->window('2026-03-04 23:00', '2026-03-05 01:00')->isSameDay());
  }

  /**
   * ISO weekday numbering: Monday is 1, Sunday is 7.
   */
  #[DataProvider('weekdayProvider')]
  public function testWeekday(string $date, int $expected, string $name): void {
    $window = $this->window($date . ' 10:00', $date . ' 11:00');

    $this->assertSame($expected, $window->weekday());
    $this->assertSame($name, $window->dayName());
  }

  /**
   * Dates with their ISO weekday and name.
   *
   * @return array<string, array{string, int, string}>
   *   Test cases.
   */
  public static function weekdayProvider(): array {
    return [
      'monday' => ['2026-03-02', 1, 'Monday'],
      'friday' => ['2026-03-06', 5, 'Friday'],
      'sunday' => ['2026-03-08', 7, 'Sunday'],
    ];
  }

  /**
   * Duration is the plain difference, never negative.
   */
  public function testDuration(): void {
    $this->assertSame(5400, $this->window('2026-03-04 09:00', '2026-03-04 10:30')->duration());
    $this->assertSame(0, $this->window('2026-03-04 10:00', '2026-03-04 09:00')->duration());
  }

  /**
   * Minutes format as a 24-hour clock.
   */
  #[DataProvider('formatProvider')]
  public function testFormatMinutes(int $minutes, string $expected): void {
    $this->assertSame($expected, TimeWindow::formatMinutes($minutes));
  }

  /**
   * Minute values and their formatted equivalents.
   *
   * @return array<string, array{int, string}>
   *   Test cases.
   */
  public static function formatProvider(): array {
    return [
      'midnight' => [0, '00:00'],
      'nine' => [540, '09:00'],
      'half five' => [1050, '17:30'],
      'end of day' => [1440, '24:00'],
    ];
  }

  /**
   * The week runs Monday to Monday.
   */
  public function testWeekBoundsStartOnMonday(): void {
    $thursday = (new \DateTimeImmutable('2026-03-05 14:00', new \DateTimeZone(self::TZ)))->getTimestamp();
    [$start, $end] = TimeWindow::weekBounds($thursday, self::TZ);

    $format = static fn (int $ts): string => (new \DateTimeImmutable('@' . $ts))
      ->setTimezone(new \DateTimeZone(self::TZ))
      ->format('Y-m-d H:i');

    $this->assertSame('2026-03-02 00:00', $format($start));
    $this->assertSame('2026-03-09 00:00', $format($end));
  }

  /**
   * A Monday belongs to its own week, not the one before.
   */
  public function testWeekBoundsStartingMonday(): void {
    $monday = (new \DateTimeImmutable('2026-03-02 00:30', new \DateTimeZone(self::TZ)))->getTimestamp();
    [$start, $end] = TimeWindow::weekBounds($monday, self::TZ);

    $this->assertLessThanOrEqual($monday, $start);
    $this->assertGreaterThan($monday, $end);
    $this->assertSame(7 * 86400, $end - $start);
  }

  /**
   * The week containing a clock change is not seven flat days long.
   *
   * British clocks go forward on 29 March 2026, so that week is an hour
   * short. Anything computing a week as "+604800 seconds" would drift.
   */
  public function testWeekBoundsAcrossSpringForward(): void {
    $inThatWeek = (new \DateTimeImmutable('2026-03-25 12:00', new \DateTimeZone(self::TZ)))->getTimestamp();
    [$start, $end] = TimeWindow::weekBounds($inThatWeek, self::TZ);

    $this->assertSame(7 * 86400 - 3600, $end - $start, 'The spring-forward week is an hour shorter.');
  }

}
