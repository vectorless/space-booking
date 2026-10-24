<?php

declare(strict_types=1);

namespace Drupal\space_booking\Entity;

use Drupal\Core\Entity\Attribute\ContentEntityType;
use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityChangedTrait;
use Drupal\Core\Entity\EntityDeleteForm;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\Routing\AdminHtmlRouteProvider;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\space_booking\BookingAccessControlHandler;
use Drupal\space_booking\BookingInterface;
use Drupal\space_booking\BookingListBuilder;
use Drupal\space_booking\Enum\BookingStatus;
use Drupal\space_booking\Enum\BookingType;
use Drupal\space_booking\Form\BookingForm;
use Drupal\user\EntityOwnerTrait;
use Drupal\user\UserInterface;

/**
 * A reservation of one or more spaces.
 */
#[ContentEntityType(
  id: 'space_booking_booking',
  label: new TranslatableMarkup('Booking'),
  label_collection: new TranslatableMarkup('Bookings'),
  label_singular: new TranslatableMarkup('booking'),
  label_plural: new TranslatableMarkup('bookings'),
  entity_keys: [
    'id' => 'id',
    'uuid' => 'uuid',
    'label' => 'title',
    'owner' => 'uid',
  ],
  handlers: [
    'list_builder' => BookingListBuilder::class,
    'access' => BookingAccessControlHandler::class,
    'form' => [
      'default' => BookingForm::class,
      'add' => BookingForm::class,
      'edit' => BookingForm::class,
      'delete' => EntityDeleteForm::class,
    ],
    'route_provider' => ['html' => AdminHtmlRouteProvider::class],
  ],
  links: [
    'canonical' => '/admin/content/bookings/{space_booking_booking}',
    'add-form' => '/admin/content/bookings/add',
    'edit-form' => '/admin/content/bookings/{space_booking_booking}/edit',
    'delete-form' => '/admin/content/bookings/{space_booking_booking}/delete',
    'collection' => '/admin/content/bookings',
  ],
  admin_permission: 'administer space booking',
  base_table: 'space_booking_booking',
  constraints: [
    'SpaceAvailable' => [],
    'BookingDuration' => [],
    'BookingWindow' => [],
    'OpeningHours' => [],
    'BookingQuota' => [],
  ],
  label_count: [
    'singular' => '@count booking',
    'plural' => '@count bookings',
  ],
)]
final class Booking extends ContentEntityBase implements BookingInterface {

  use EntityChangedTrait;
  use EntityOwnerTrait;

  /**
   * {@inheritdoc}
   */
  public function getStart(): int {
    return (int) $this->get('start')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function getEnd(): int {
    return (int) $this->get('end')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function getDuration(): int {
    return max(0, $this->getEnd() - $this->getStart());
  }

  /**
   * {@inheritdoc}
   */
  public function getStatus(): BookingStatus {
    return BookingStatus::from((string) $this->get('status')->value);
  }

  /**
   * {@inheritdoc}
   */
  public function setStatus(BookingStatus $status): static {
    $this->set('status', $status->value);
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getType(): BookingType {
    return BookingType::from((string) $this->get('booking_type')->value);
  }

  /**
   * {@inheritdoc}
   */
  public function getSpaceIds(): array {
    return array_values(array_map(
      static fn (array $item): int => (int) $item['target_id'],
      $this->get('spaces')->getValue(),
    ));
  }

  /**
   * {@inheritdoc}
   */
  public function getSpaces(): array {
    $spaces = [];
    foreach ($this->get('spaces')->referencedEntities() as $space) {
      $spaces[(int) $space->id()] = $space;
    }
    return $spaces;
  }

  /**
   * {@inheritdoc}
   */
  public function getSubject(): ?EntityInterface {
    if (!$this->hasField('subject')) {
      return NULL;
    }
    $subject = $this->get('subject')->entity;
    return $subject instanceof EntityInterface ? $subject : NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function getHolder(): ?UserInterface {
    $holder = $this->get('holder')->entity;
    return $holder instanceof UserInterface ? $holder : NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function occupiesSpace(): bool {
    return $this->getStatus()->occupiesSpace();
  }

  /**
   * {@inheritdoc}
   */
  public function overlaps(int $start, int $end): bool {
    // Half-open intervals: touching ranges are not an overlap, so a booking
    // that ends at 10:00 leaves 10:00 free for the next one.
    return $this->getStart() < $end && $start < $this->getEnd();
  }

  /**
   * {@inheritdoc}
   */
  public function label(): string {
    $title = (string) $this->get('title')->value;
    if ($title !== '') {
      return $title;
    }
    $subject = $this->getSubject();
    if ($subject !== NULL) {
      return (string) $subject->label();
    }
    $holder = $this->getHolder();
    return $holder?->getDisplayName() ?? (string) $this->getType()->label();
  }

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    $fields = parent::baseFieldDefinitions($entity_type);
    $fields += static::ownerBaseFieldDefinitions($entity_type);

    $fields['spaces'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('Spaces'))
      ->setDescription(new TranslatableMarkup('One booking may occupy several spaces at once.'))
      ->setRequired(TRUE)
      ->setSetting('target_type', 'space_booking_space')
      ->setCardinality(BaseFieldDefinition::CARDINALITY_UNLIMITED)
      ->setDisplayOptions('form', ['type' => 'entity_reference_autocomplete_tags', 'weight' => 0])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['start'] = BaseFieldDefinition::create('timestamp')
      ->setLabel(new TranslatableMarkup('Starts'))
      ->setRequired(TRUE)
      ->setDisplayOptions('form', ['type' => 'datetime_timestamp', 'weight' => 1])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['end'] = BaseFieldDefinition::create('timestamp')
      ->setLabel(new TranslatableMarkup('Ends'))
      ->setRequired(TRUE)
      ->setDisplayOptions('form', ['type' => 'datetime_timestamp', 'weight' => 2])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['booking_type'] = BaseFieldDefinition::create('list_string')
      ->setLabel(new TranslatableMarkup('Type'))
      ->setRequired(TRUE)
      ->setSetting('allowed_values', array_map(
        static fn ($label): string => (string) $label,
        BookingType::options(),
      ))
      ->setDefaultValue(BookingType::User->value)
      ->setDisplayOptions('form', ['type' => 'options_buttons', 'weight' => 3])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['status'] = BaseFieldDefinition::create('list_string')
      ->setLabel(new TranslatableMarkup('Status'))
      ->setRequired(TRUE)
      ->setSetting('allowed_values', array_map(
        static fn ($label): string => (string) $label,
        BookingStatus::options(),
      ))
      ->setDefaultValue(BookingStatus::Confirmed->value)
      ->setDisplayOptions('form', ['type' => 'options_select', 'weight' => 4])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['holder'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('Held by'))
      ->setDescription(new TranslatableMarkup('The person the booking is for, who is not always the person who made it.'))
      ->setSetting('target_type', 'user')
      ->setDisplayOptions('form', ['type' => 'entity_reference_autocomplete', 'weight' => 5])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['title'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Title'))
      ->setSetting('max_length', 255)
      ->setDisplayOptions('form', ['type' => 'string_textfield', 'weight' => 6])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    // What the booking is *for*: a class, activity or event described
    // elsewhere on the site. The venue owns the room and the hour; whoever
    // runs the activity owns the words. Keeping them in separate entities is
    // what lets both be true without either overwriting the other.
    //
    // Optional, and only defined when the configured entity type exists, so
    // the module still installs on a site without it.
    // Default in code as well as in config: base fields are built while the
    // entity schema is installed, which can happen before this module's
    // config exists. Reading only from config made the field vanish from the
    // schema depending on install order. An explicit empty string still
    // switches the link off.
    $configured = \Drupal::config('space_booking.settings')->get('subject_entity_type');
    $subjectType = $configured === NULL ? 'node' : (string) $configured;
    if ($subjectType !== '' && \Drupal::entityTypeManager()->hasDefinition($subjectType)) {
      $fields['subject'] = BaseFieldDefinition::create('entity_reference')
        ->setLabel(new TranslatableMarkup('For'))
        ->setDescription(new TranslatableMarkup('The activity or event this booking is for. Its description is edited where it lives; only the time is set here.'))
        ->setSetting('target_type', $subjectType)
        ->setDisplayOptions('form', ['type' => 'entity_reference_autocomplete', 'weight' => 8])
        ->setDisplayConfigurable('form', TRUE)
        ->setDisplayConfigurable('view', TRUE);
    }

    $fields['notes'] = BaseFieldDefinition::create('string_long')
      ->setLabel(new TranslatableMarkup('Notes'))
      ->setDisplayOptions('form', ['type' => 'string_textarea', 'weight' => 7])
      ->setDisplayConfigurable('form', TRUE);

    $fields['created'] = BaseFieldDefinition::create('created')
      ->setLabel(new TranslatableMarkup('Created'));

    $fields['changed'] = BaseFieldDefinition::create('changed')
      ->setLabel(new TranslatableMarkup('Changed'));

    return $fields;
  }

}
