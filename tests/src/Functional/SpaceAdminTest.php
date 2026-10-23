<?php

declare(strict_types=1);

namespace Drupal\Tests\space_booking\Functional;

use Drupal\Tests\BrowserTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Administering spaces and the module's settings.
 */
#[Group('space_booking')]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(FALSE)]
final class SpaceAdminTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['space_booking'];

  /**
   * An administrator creates a space through the UI.
   */
  public function testAdministratorCreatesSpace(): void {
    $this->drupalLogin($this->drupalCreateUser(['administer space booking']));

    $this->drupalGet('/admin/structure/space-booking/spaces/add');
    $this->assertSession()->statusCodeEquals(200);

    $this->submitForm([
      'label[0][value]' => 'Langside Hall',
      'capacity[0][value]' => 60,
    ], 'Save');

    $this->assertSession()->pageTextContains('Saved the space Langside Hall.');
    $this->assertSession()->pageTextContains('Langside Hall');

    $spaces = \Drupal::entityTypeManager()->getStorage('space_booking_space')->loadMultiple();
    $this->assertCount(1, $spaces);
    $space = reset($spaces);
    $this->assertSame(60, $space->getCapacity());
    $this->assertTrue($space->isBookable(), 'Spaces are bookable unless told otherwise.');
  }

  /**
   * The space admin screens are closed to ordinary users.
   */
  public function testSpaceAdminIsRestricted(): void {
    $this->drupalLogin($this->drupalCreateUser(['view space schedule', 'create space booking']));

    $this->drupalGet('/admin/structure/space-booking/spaces');
    $this->assertSession()->statusCodeEquals(403);

    $this->drupalGet('/admin/config/content/space-booking');
    $this->assertSession()->statusCodeEquals(403);
  }

  /**
   * Settings save and come back out again.
   */
  public function testSettingsRoundTrip(): void {
    $this->drupalLogin($this->drupalCreateUser(['administer space booking']));
    $this->drupalGet('/admin/config/content/space-booking');
    $this->assertSession()->statusCodeEquals(200);

    $this->submitForm([
      'granularity_minutes' => 30,
      'min_duration_minutes' => 60,
      'max_duration_minutes' => 240,
      'buffer_minutes' => 15,
      'max_advance_days' => 30,
      'hours[1][open]' => '08:00',
      'hours[1][close]' => '22:00',
    ], 'Save configuration');

    $this->assertSession()->pageTextContains('The configuration options have been saved.');

    $config = $this->config('space_booking.settings');
    $this->assertSame(30, $config->get('granularity_minutes'));
    $this->assertSame(15, $config->get('buffer_minutes'));
    $this->assertSame(480, $config->get('opening_hours.1.open'), 'Times are stored as minutes since midnight.');
    $this->assertSame(1320, $config->get('opening_hours.1.close'));
  }

  /**
   * A closing time before the opening time is rejected.
   */
  public function testClosingBeforeOpeningIsRejected(): void {
    $this->drupalLogin($this->drupalCreateUser(['administer space booking']));
    $this->drupalGet('/admin/config/content/space-booking');

    $this->submitForm([
      'hours[1][open]' => '17:00',
      'hours[1][close]' => '09:00',
    ], 'Save configuration');

    $this->assertSession()->pageTextContains('closing time must be after opening time');
  }

  /**
   * A time that is not a time is rejected rather than silently zeroed.
   */
  public function testUnparseableTimeIsRejected(): void {
    $this->drupalLogin($this->drupalCreateUser(['administer space booking']));
    $this->drupalGet('/admin/config/content/space-booking');

    $this->submitForm(['hours[2][open]' => 'half nine'], 'Save configuration');

    $this->assertSession()->pageTextContains('must look like');
  }

  /**
   * A maximum shorter than the minimum is rejected.
   */
  public function testContradictoryDurationsAreRejected(): void {
    $this->drupalLogin($this->drupalCreateUser(['administer space booking']));
    $this->drupalGet('/admin/config/content/space-booking');

    $this->submitForm([
      'min_duration_minutes' => 120,
      'max_duration_minutes' => 60,
    ], 'Save configuration');

    $this->assertSession()->pageTextContains('cannot be shorter than');
  }

}
