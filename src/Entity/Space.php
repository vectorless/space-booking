<?php

declare(strict_types=1);

namespace Drupal\space_booking\Entity;

use Drupal\Core\Entity\Attribute\ContentEntityType;
use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityChangedTrait;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\space_booking\Form\SpaceForm;
use Drupal\space_booking\SpaceAccessControlHandler;
use Drupal\space_booking\SpaceInterface;
use Drupal\space_booking\SpaceListBuilder;
use Drupal\Core\Entity\Routing\AdminHtmlRouteProvider;
use Drupal\Core\Entity\EntityDeleteForm;
use Drupal\user\EntityOwnerTrait;

/**
 * A bookable resource.
 */
#[ContentEntityType(
  id: 'space_booking_space',
  label: new TranslatableMarkup('Space'),
  label_collection: new TranslatableMarkup('Spaces'),
  label_singular: new TranslatableMarkup('space'),
  label_plural: new TranslatableMarkup('spaces'),
  entity_keys: [
    'id' => 'id',
    'uuid' => 'uuid',
    'label' => 'label',
    'owner' => 'uid',
    'published' => 'status',
  ],
  handlers: [
    'list_builder' => SpaceListBuilder::class,
    'access' => SpaceAccessControlHandler::class,
    'form' => [
      'default' => SpaceForm::class,
      'add' => SpaceForm::class,
      'edit' => SpaceForm::class,
      'delete' => EntityDeleteForm::class,
    ],
    'route_provider' => ['html' => AdminHtmlRouteProvider::class],
  ],
  links: [
    'canonical' => '/admin/structure/space-booking/spaces/{space_booking_space}',
    'add-form' => '/admin/structure/space-booking/spaces/add',
    'edit-form' => '/admin/structure/space-booking/spaces/{space_booking_space}/edit',
    'delete-form' => '/admin/structure/space-booking/spaces/{space_booking_space}/delete',
    'collection' => '/admin/structure/space-booking/spaces',
  ],
  admin_permission: 'administer space booking',
  base_table: 'space_booking_space',
  label_count: [
    'singular' => '@count space',
    'plural' => '@count spaces',
  ],
)]
final class Space extends ContentEntityBase implements SpaceInterface {

  use EntityChangedTrait;
  use EntityOwnerTrait;

  /**
   * {@inheritdoc}
   */
  public function isBookable(): bool {
    return (bool) $this->get('bookable')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function requiresApproval(): bool {
    return (bool) $this->get('requires_approval')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function getCapacity(): ?int {
    $value = $this->get('capacity')->value;
    return $value === NULL || $value === '' ? NULL : (int) $value;
  }

  /**
   * {@inheritdoc}
   */
  public function getTagIds(): array {
    return array_map(
      static fn (array $item): int => (int) $item['target_id'],
      $this->get('tags')->getValue(),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getConflictingSpaceIds(): array {
    return array_map(
      static fn (array $item): int => (int) $item['target_id'],
      $this->get('conflicts_with')->getValue(),
    );
  }

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    $fields = parent::baseFieldDefinitions($entity_type);
    $fields += static::ownerBaseFieldDefinitions($entity_type);

    $fields['label'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Name'))
      ->setRequired(TRUE)
      ->setSetting('max_length', 128)
      ->setDisplayOptions('form', ['type' => 'string_textfield', 'weight' => -10])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['status'] = BaseFieldDefinition::create('boolean')
      ->setLabel(new TranslatableMarkup('Enabled'))
      ->setDescription(new TranslatableMarkup('Disabled spaces disappear from the schedule. Existing bookings are kept.'))
      ->setDefaultValue(TRUE)
      ->setDisplayOptions('form', ['type' => 'boolean_checkbox', 'weight' => 10])
      ->setDisplayConfigurable('form', TRUE);

    $fields['bookable'] = BaseFieldDefinition::create('boolean')
      ->setLabel(new TranslatableMarkup('Bookable'))
      ->setDescription(new TranslatableMarkup('Uncheck for a space that is permanently assigned. It still shows on the schedule but nobody can reserve it.'))
      ->setDefaultValue(TRUE)
      ->setDisplayOptions('form', ['type' => 'boolean_checkbox', 'weight' => 11])
      ->setDisplayConfigurable('form', TRUE);

    $fields['requires_approval'] = BaseFieldDefinition::create('boolean')
      ->setLabel(new TranslatableMarkup('Bookings need approval'))
      ->setDescription(new TranslatableMarkup('Reservations here are held as pending until an administrator approves them.'))
      ->setDefaultValue(FALSE)
      ->setDisplayOptions('form', ['type' => 'boolean_checkbox', 'weight' => 12])
      ->setDisplayConfigurable('form', TRUE);

    $fields['description'] = BaseFieldDefinition::create('text_long')
      ->setLabel(new TranslatableMarkup('Description'))
      ->setDisplayOptions('form', ['type' => 'text_textarea', 'weight' => 5])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['capacity'] = BaseFieldDefinition::create('integer')
      ->setLabel(new TranslatableMarkup('Capacity'))
      ->setDescription(new TranslatableMarkup('How many people fit. Leave empty for no limit.'))
      ->setSetting('min', 1)
      ->setDisplayOptions('form', ['type' => 'number', 'weight' => 6])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['space_type'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('Type'))
      ->setSetting('target_type', 'taxonomy_term')
      ->setSetting('handler_settings', ['target_bundles' => ['space_booking_type' => 'space_booking_type']])
      ->setDisplayOptions('form', ['type' => 'entity_reference_autocomplete', 'weight' => 7])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['tags'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('Tags'))
      ->setDescription(new TranslatableMarkup('Used to filter the schedule and to scope booking rules.'))
      ->setSetting('target_type', 'taxonomy_term')
      ->setSetting('handler_settings', ['target_bundles' => ['space_booking_tag' => 'space_booking_tag']])
      ->setCardinality(BaseFieldDefinition::CARDINALITY_UNLIMITED)
      ->setDisplayOptions('form', ['type' => 'entity_reference_autocomplete_tags', 'weight' => 8])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['conflicts_with'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('Cannot be used at the same time as'))
      ->setDescription(new TranslatableMarkup('For spaces that physically overlap, such as a hall and the halves it divides into.'))
      ->setSetting('target_type', 'space_booking_space')
      ->setCardinality(BaseFieldDefinition::CARDINALITY_UNLIMITED)
      ->setDisplayOptions('form', ['type' => 'entity_reference_autocomplete_tags', 'weight' => 9])
      ->setDisplayConfigurable('form', TRUE);

    $fields['weight'] = BaseFieldDefinition::create('integer')
      ->setLabel(new TranslatableMarkup('Order'))
      ->setDescription(new TranslatableMarkup('Lower values appear first on the schedule.'))
      ->setDefaultValue(0)
      ->setDisplayOptions('form', ['type' => 'number', 'weight' => 20])
      ->setDisplayConfigurable('form', TRUE);

    $fields['created'] = BaseFieldDefinition::create('created')
      ->setLabel(new TranslatableMarkup('Created'));

    $fields['changed'] = BaseFieldDefinition::create('changed')
      ->setLabel(new TranslatableMarkup('Changed'));

    return $fields;
  }

}
