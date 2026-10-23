<?php

declare(strict_types=1);

namespace Drupal\space_booking;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Entity\EntityAccessControlHandler;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * Who may see and change a booking.
 *
 * A booking carries somebody's name and plans, so viewing it is not the same
 * as viewing the schedule: the grid can show that an hour is taken without
 * revealing who took it.
 */
final class BookingAccessControlHandler extends EntityAccessControlHandler {

  /**
   * {@inheritdoc}
   */
  protected function checkAccess(EntityInterface $entity, $operation, AccountInterface $account): AccessResultInterface {
    \assert($entity instanceof BookingInterface);

    $admin = AccessResult::allowedIfHasPermission($account, 'administer space booking');
    if ($admin->isAllowed()) {
      return $admin;
    }

    $holder = $entity->getHolder();
    $isOwn = ($holder !== NULL && (int) $holder->id() === (int) $account->id())
      || (int) $entity->getOwnerId() === (int) $account->id();

    $result = match ($operation) {
      'view' => $isOwn
        ? AccessResult::allowed()
        : AccessResult::allowedIfHasPermission($account, 'view any space booking'),
      'update' => AccessResult::allowedIf($isOwn)
        ->andIf(AccessResult::allowedIfHasPermission($account, 'edit own space bookings')),
      'delete' => AccessResult::allowedIf($isOwn)
        ->andIf(AccessResult::allowedIfHasPermission($account, 'delete own space bookings')),
      default => AccessResult::neutral(),
    };

    // The result depends on who is asking and on who holds the booking, so it
    // must not be cached and served to the next visitor.
    return $result->cachePerUser()->addCacheableDependency($entity);
  }

  /**
   * {@inheritdoc}
   */
  protected function checkCreateAccess(AccountInterface $account, array $context, $entity_bundle = NULL): AccessResultInterface {
    return AccessResult::allowedIfHasPermission($account, 'create space booking')
      ->orIf(AccessResult::allowedIfHasPermission($account, 'administer space booking'));
  }

}
