<?php

declare(strict_types=1);

namespace Drupal\space_booking\Form;

use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\space_booking\BookingInterface;
use Drupal\space_booking\BookingManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Approve or reject a pending booking request.
 */
final class BookingDecisionForm extends ConfirmFormBase {

  /**
   * The booking being decided on.
   */
  private ?BookingInterface $booking = NULL;

  /**
   * Either 'approve' or 'reject'.
   */
  private string $decision = 'approve';

  public function __construct(
    private readonly BookingManager $bookingManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self($container->get('space_booking.manager'));
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'space_booking_decision';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?BookingInterface $space_booking_booking = NULL, string $decision = 'approve'): array {
    $this->booking = $space_booking_booking;
    $this->decision = $decision === 'reject' ? 'reject' : 'approve';
    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion(): \Stringable {
    return $this->decision === 'approve'
      ? $this->t('Approve the booking %label?', ['%label' => $this->booking?->label() ?? ''])
      : $this->t('Reject the booking %label?', ['%label' => $this->booking?->label() ?? '']);
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription(): \Stringable {
    return $this->decision === 'approve'
      ? $this->t('The holder will be told the booking is confirmed.')
      : $this->t('The slot is released and the holder is told the request was refused.');
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl(): Url {
    return Url::fromRoute('space_booking.approvals');
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    if ($this->booking === NULL) {
      return;
    }

    if ($this->decision === 'approve') {
      if ($this->bookingManager->approve($this->booking)) {
        $this->messenger()->addStatus($this->t('Booking approved.'));
      }
      else {
        // Somebody else took the slot while this request waited.
        $this->messenger()->addError($this->t('That slot is no longer free, so the request cannot be approved. Reject it, or move it to another time.'));
      }
    }
    else {
      $this->bookingManager->reject($this->booking);
      $this->messenger()->addStatus($this->t('Booking rejected.'));
    }

    $form_state->setRedirectUrl($this->getCancelUrl());
  }

}
