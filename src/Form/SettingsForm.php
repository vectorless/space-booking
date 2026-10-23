<?php

declare(strict_types=1);

namespace Drupal\space_booking\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\space_booking\BookingSettings;
use Drupal\space_booking\TimeWindow;

/**
 * Module configuration: the rules every booking is measured against.
 */
final class SettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'space_booking_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return [BookingSettings::CONFIG_NAME];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config(BookingSettings::CONFIG_NAME);

    $form['enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Accept bookings'),
      '#description' => $this->t('Switch off to close bookings while leaving the schedule visible.'),
      '#default_value' => $config->get('enabled'),
    ];

    $form['slots'] = [
      '#type' => 'details',
      '#title' => $this->t('Slots and length'),
      '#open' => TRUE,
    ];
    $form['slots']['granularity_minutes'] = [
      '#type' => 'number',
      '#title' => $this->t('Slot size'),
      '#field_suffix' => $this->t('minutes'),
      '#min' => 1,
      '#max' => 240,
      '#required' => TRUE,
      '#default_value' => $config->get('granularity_minutes'),
    ];
    $form['slots']['min_duration_minutes'] = [
      '#type' => 'number',
      '#title' => $this->t('Shortest booking'),
      '#field_suffix' => $this->t('minutes'),
      '#min' => 0,
      '#default_value' => $config->get('min_duration_minutes'),
    ];
    $form['slots']['max_duration_minutes'] = [
      '#type' => 'number',
      '#title' => $this->t('Longest booking'),
      '#description' => $this->t('Zero for no maximum.'),
      '#field_suffix' => $this->t('minutes'),
      '#min' => 0,
      '#default_value' => $config->get('max_duration_minutes'),
    ];
    $form['slots']['buffer_minutes'] = [
      '#type' => 'number',
      '#title' => $this->t('Gap between bookings'),
      '#description' => $this->t('Time kept free either side of every booking, for turnaround.'),
      '#field_suffix' => $this->t('minutes'),
      '#min' => 0,
      '#default_value' => $config->get('buffer_minutes'),
    ];

    $form['window'] = [
      '#type' => 'details',
      '#title' => $this->t('How far ahead'),
      '#open' => TRUE,
    ];
    $form['window']['min_advance_minutes'] = [
      '#type' => 'number',
      '#title' => $this->t('Minimum notice'),
      '#description' => $this->t('Zero to allow booking right up to the start.'),
      '#field_suffix' => $this->t('minutes'),
      '#min' => 0,
      '#default_value' => $config->get('min_advance_minutes'),
    ];
    $form['window']['max_advance_days'] = [
      '#type' => 'number',
      '#title' => $this->t('Furthest ahead'),
      '#description' => $this->t('Zero for no limit.'),
      '#field_suffix' => $this->t('days'),
      '#min' => 0,
      '#default_value' => $config->get('max_advance_days'),
    ];
    $form['window']['quota_minutes_per_week'] = [
      '#type' => 'number',
      '#title' => $this->t('Weekly allowance per person'),
      '#description' => $this->t('Zero for unlimited.'),
      '#field_suffix' => $this->t('minutes'),
      '#min' => 0,
      '#default_value' => $config->get('quota_minutes_per_week'),
    ];
    $form['window']['schedule_days'] = [
      '#type' => 'number',
      '#title' => $this->t('Days shown on the schedule'),
      '#min' => 1,
      '#max' => 90,
      '#default_value' => $config->get('schedule_days'),
    ];

    $form['hours'] = [
      '#type' => 'details',
      '#title' => $this->t('Opening hours'),
      '#open' => TRUE,
      '#tree' => TRUE,
    ];
    foreach (self::weekdays() as $weekday => $name) {
      $day = $config->get('opening_hours.' . $weekday) ?? [];
      $form['hours'][$weekday] = [
        '#type' => 'fieldset',
        '#title' => $name,
      ];
      $form['hours'][$weekday]['closed'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Closed'),
        '#default_value' => $day['closed'] ?? FALSE,
      ];
      $form['hours'][$weekday]['open'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Opens'),
        '#size' => 6,
        '#default_value' => TimeWindow::formatMinutes((int) ($day['open'] ?? 540)),
        '#description' => $this->t('24-hour, for example 09:00.'),
      ];
      $form['hours'][$weekday]['close'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Closes'),
        '#size' => 6,
        '#default_value' => TimeWindow::formatMinutes((int) ($day['close'] ?? 1320)),
      ];
    }

    $form['notify'] = [
      '#type' => 'details',
      '#title' => $this->t('Notifications'),
      '#open' => TRUE,
    ];
    $form['notify']['notify_holder'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Email the person who booked'),
      '#default_value' => $config->get('notify_holder'),
    ];
    $form['notify']['notify_venue'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Email the venue'),
      '#default_value' => $config->get('notify_venue'),
    ];
    $form['notify']['venue_email'] = [
      '#type' => 'email',
      '#title' => $this->t('Venue contact email'),
      '#default_value' => $config->get('venue_email'),
      '#states' => [
        'visible' => [':input[name="notify_venue"]' => ['checked' => TRUE]],
      ],
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);

    foreach (self::weekdays() as $weekday => $name) {
      $day = $form_state->getValue(['hours', $weekday]);
      if (($day['closed'] ?? FALSE)) {
        continue;
      }
      $open = self::parseTime((string) ($day['open'] ?? ''));
      $close = self::parseTime((string) ($day['close'] ?? ''));

      if ($open === NULL) {
        $form_state->setErrorByName("hours][$weekday][open", $this->t('@day: opening time must look like 09:00.', ['@day' => $name]));
      }
      if ($close === NULL) {
        $form_state->setErrorByName("hours][$weekday][close", $this->t('@day: closing time must look like 17:30.', ['@day' => $name]));
      }
      if ($open !== NULL && $close !== NULL && $close <= $open) {
        $form_state->setErrorByName("hours][$weekday][close", $this->t('@day: closing time must be after opening time.', ['@day' => $name]));
      }
    }

    $min = (int) $form_state->getValue('min_duration_minutes');
    $max = (int) $form_state->getValue('max_duration_minutes');
    if ($max > 0 && $min > $max) {
      $form_state->setErrorByName('max_duration_minutes', $this->t('The longest booking cannot be shorter than the shortest one.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $config = $this->config(BookingSettings::CONFIG_NAME);

    foreach ([
      'enabled', 'granularity_minutes', 'min_duration_minutes', 'max_duration_minutes',
      'buffer_minutes', 'min_advance_minutes', 'max_advance_days',
      'quota_minutes_per_week', 'schedule_days', 'notify_holder', 'notify_venue', 'venue_email',
    ] as $key) {
      $config->set($key, $form_state->getValue($key));
    }

    foreach (self::weekdays() as $weekday => $name) {
      $day = $form_state->getValue(['hours', $weekday]);
      $config->set('opening_hours.' . $weekday, [
        'closed' => (bool) ($day['closed'] ?? FALSE),
        'open' => self::parseTime((string) ($day['open'] ?? '')) ?? 0,
        'close' => self::parseTime((string) ($day['close'] ?? '')) ?? 0,
      ]);
    }

    $config->save();
    parent::submitForm($form, $form_state);
  }

  /**
   * Weekday numbers mapped to names.
   *
   * @return array<int, string>
   *   Names keyed by ISO weekday.
   */
  private static function weekdays(): array {
    return [
      1 => 'Monday',
      2 => 'Tuesday',
      3 => 'Wednesday',
      4 => 'Thursday',
      5 => 'Friday',
      6 => 'Saturday',
      7 => 'Sunday',
    ];
  }

  /**
   * Parse "HH:MM" into minutes since midnight.
   *
   * @param string $value
   *   The submitted time.
   *
   * @return int|null
   *   Minutes since midnight, or NULL when unparseable.
   */
  private static function parseTime(string $value): ?int {
    if (preg_match('/^([01]?\d|2[0-4]):([0-5]\d)$/', trim($value), $matches) !== 1) {
      return NULL;
    }
    $minutes = ((int) $matches[1]) * 60 + (int) $matches[2];
    return $minutes > 1440 ? NULL : $minutes;
  }

}
