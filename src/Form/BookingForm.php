<?php

declare(strict_types=1);

namespace Drupal\space_booking\Form;

use Drupal\Core\Entity\ContentEntityForm;
use Drupal\Core\Form\FormStateInterface;
use Drupal\space_booking\BookingInterface;
use Drupal\space_booking\BookingManager;
use Drupal\space_booking\Enum\BookingStatus;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * The booking form.
 *
 * Reachable both from the schedule, where a clicked cell pre-fills the space
 * and time, and from the admin screens, where staff pick everything by hand.
 */
final class BookingForm extends ContentEntityForm {

  /**
   * The booking lifecycle service.
   */
  private BookingManager $bookingManager;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    $form = parent::create($container);
    $form->bookingManager = $container->get('space_booking.manager');
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function form(array $form, FormStateInterface $form_state): array {
    $booking = $this->entity;
    \assert($booking instanceof BookingInterface);

    if ($booking->isNew()) {
      $this->applyRequestDefaults($booking);
    }

    $form = parent::form($form, $form_state);

    if (!$this->currentUser()->hasPermission('administer space booking')) {
      // Ordinary visitors do not choose their own status, book on behalf of
      // other people, or record internal closures.
      foreach (['status', 'booking_type', 'holder', 'notes'] as $field) {
        if (isset($form[$field])) {
          $form[$field]['#access'] = FALSE;
        }
      }
    }

    if (!$this->bookingManager->enabled()) {
      $this->messenger()->addWarning($this->t('Booking is currently closed.'));
      $form['#disabled'] = TRUE;
    }

    return $form;
  }

  /**
   * Pre-fill from query parameters so a schedule cell can open this form.
   */
  private function applyRequestDefaults(BookingInterface $booking): void {
    $query = $this->getRequest()->query;

    $spaces = array_values(array_filter(array_map('intval', (array) $query->all('spaces'))));
    if ($spaces !== []) {
      $booking->set('spaces', $spaces);
    }

    foreach (['start', 'end'] as $key) {
      $value = (int) $query->get($key, 0);
      if ($value > 0) {
        $booking->set($key, $value);
      }
    }

    if ($booking->get('holder')->isEmpty()) {
      $booking->set('holder', $this->bookingManager->currentUserId());
    }
  }

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state): int {
    $booking = $this->entity;
    \assert($booking instanceof BookingInterface);

    $isNew = $booking->isNew();
    if ($isNew) {
      if ($booking->get('holder')->isEmpty()) {
        $booking->set('holder', $this->bookingManager->currentUserId());
      }
      $booking->setStatus($this->bookingManager->initialStatus($booking));
    }

    $result = parent::save($form, $form_state);

    if ($isNew) {
      $this->bookingManager->notify($booking, 'created');
      $this->messenger()->addStatus(
        $booking->getStatus() === BookingStatus::Pending
          ? $this->t('Your request has been sent. You will hear once it is approved.')
          : $this->t('Your booking is confirmed.')
      );
    }
    else {
      $this->messenger()->addStatus($this->t('The booking has been updated.'));
    }

    $form_state->setRedirect('space_booking.schedule');
    return $result;
  }

}
