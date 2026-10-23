<?php

declare(strict_types=1);

namespace Drupal\Tests\space_booking\Unit;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\space_booking\Enum\BookingStatus;
use Drupal\space_booking\Enum\BookingType;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * The small decisions encoded in the enums.
 */
#[CoversClass(BookingStatus::class)]
#[CoversClass(BookingType::class)]
#[Group('space_booking')]
final class BookingEnumTest extends UnitTestCase {

  /**
   * Pending bookings hold their slot.
   *
   * If a request released the space while an administrator thought about it,
   * two people could be promised the same hour.
   */
  #[DataProvider('occupancyProvider')]
  public function testOccupiesSpace(BookingStatus $status, bool $expected): void {
    $this->assertSame($expected, $status->occupiesSpace());
  }

  /**
   * Statuses and whether they hold a space.
   *
   * @return array<string, array{BookingStatus, bool}>
   *   Test cases.
   */
  public static function occupancyProvider(): array {
    return [
      'pending holds the slot' => [BookingStatus::Pending, TRUE],
      'confirmed holds the slot' => [BookingStatus::Confirmed, TRUE],
      'cancelled releases it' => [BookingStatus::Cancelled, FALSE],
      'rejected releases it' => [BookingStatus::Rejected, FALSE],
    ];
  }

  /**
   * The query helper lists exactly the occupying statuses.
   */
  public function testOccupyingValues(): void {
    $values = BookingStatus::occupyingValues();

    sort($values);
    $this->assertSame(['confirmed', 'pending'], $values);
  }

  /**
   * Only a user booking is a public reservation.
   *
   * Internal use and closures still occupy the space, but the rules written
   * for members must not stop staff recording them.
   */
  #[DataProvider('publicProvider')]
  public function testIsPublic(BookingType $type, bool $expected): void {
    $this->assertSame($expected, $type->isPublic());
  }

  /**
   * Types and whether they are public reservations.
   *
   * @return array<string, array{BookingType, bool}>
   *   Test cases.
   */
  public static function publicProvider(): array {
    return [
      'user' => [BookingType::User, TRUE],
      'internal' => [BookingType::Internal, FALSE],
      'unavailable' => [BookingType::Unavailable, FALSE],
    ];
  }

  /**
   * Every case offers a label, and options cover them all.
   */
  public function testOptionsCoverEveryCase(): void {
    $this->assertCount(count(BookingStatus::cases()), BookingStatus::options());
    $this->assertCount(count(BookingType::cases()), BookingType::options());

    // Labels are only asserted to exist, not rendered: turning a
    // TranslatableMarkup into a string needs a container, which a unit test
    // deliberately does not have.
    foreach (BookingStatus::cases() as $case) {
      $this->assertArrayHasKey($case->value, BookingStatus::options());
      $this->assertInstanceOf(TranslatableMarkup::class, $case->label());
    }
    foreach (BookingType::cases() as $case) {
      $this->assertArrayHasKey($case->value, BookingType::options());
      $this->assertInstanceOf(TranslatableMarkup::class, $case->label());
    }
  }

}
