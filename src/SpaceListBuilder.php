<?php

declare(strict_types=1);

namespace Drupal\space_booking;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityListBuilder;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Lists spaces for administrators.
 */
final class SpaceListBuilder extends EntityListBuilder {

  /**
   * {@inheritdoc}
   */
  public function buildHeader(): array {
    return [
      'label' => new TranslatableMarkup('Name'),
      'capacity' => new TranslatableMarkup('Capacity'),
      'bookable' => new TranslatableMarkup('Bookable'),
      'approval' => new TranslatableMarkup('Needs approval'),
      'status' => new TranslatableMarkup('Enabled'),
    ] + parent::buildHeader();
  }

  /**
   * {@inheritdoc}
   */
  public function buildRow(EntityInterface $entity): array {
    \assert($entity instanceof SpaceInterface);
    $yes = new TranslatableMarkup('Yes');
    $no = new TranslatableMarkup('No');

    return [
      'label' => $entity->label(),
      'capacity' => $entity->getCapacity() ?? new TranslatableMarkup('No limit'),
      'bookable' => $entity->isBookable() ? $yes : $no,
      'approval' => $entity->requiresApproval() ? $yes : $no,
      'status' => $entity->get('status')->value ? $yes : $no,
    ] + parent::buildRow($entity);
  }

  /**
   * {@inheritdoc}
   */
  protected function getEntityIds(): array {
    return $this->getStorage()->getQuery()
      ->accessCheck(TRUE)
      ->sort('weight')
      ->sort('label')
      ->pager($this->limit)
      ->execute();
  }

}
