<?php

declare(strict_types=1);

namespace Drupal\space_booking;

use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityListBuilder;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Lists bookings, most recent first.
 */
final class BookingListBuilder extends EntityListBuilder {

  public function __construct(
    EntityTypeInterface $entity_type,
    EntityStorageInterface $storage,
    private readonly DateFormatterInterface $dateFormatter,
  ) {
    parent::__construct($entity_type, $storage);
  }

  /**
   * {@inheritdoc}
   */
  public static function createInstance(ContainerInterface $container, EntityTypeInterface $entity_type): static {
    return new static(
      $entity_type,
      $container->get('entity_type.manager')->getStorage($entity_type->id()),
      $container->get('date.formatter'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function buildHeader(): array {
    return [
      'title' => new TranslatableMarkup('Booking'),
      'spaces' => new TranslatableMarkup('Spaces'),
      'when' => new TranslatableMarkup('When'),
      'holder' => new TranslatableMarkup('Held by'),
      'status' => new TranslatableMarkup('Status'),
    ] + parent::buildHeader();
  }

  /**
   * {@inheritdoc}
   */
  public function buildRow(EntityInterface $entity): array {
    \assert($entity instanceof BookingInterface);
    $spaces = array_map(
      static fn (SpaceInterface $space): string => (string) $space->label(),
      $entity->getSpaces(),
    );

    return [
      'title' => $entity->label(),
      'spaces' => implode(', ', $spaces),
      'when' => new TranslatableMarkup('@from to @to', [
        '@from' => $this->dateFormatter->format($entity->getStart(), 'short'),
        '@to' => $this->dateFormatter->format($entity->getEnd(), 'short'),
      ]),
      'holder' => $entity->getHolder()?->getDisplayName() ?? '',
      'status' => $entity->getStatus()->label(),
    ] + parent::buildRow($entity);
  }

  /**
   * {@inheritdoc}
   */
  protected function getEntityIds(): array {
    return $this->getStorage()->getQuery()
      ->accessCheck(TRUE)
      ->sort('start', 'DESC')
      ->pager($this->limit)
      ->execute();
  }

}
