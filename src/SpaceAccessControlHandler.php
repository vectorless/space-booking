<?php

declare(strict_types=1);

namespace Drupal\space_booking;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Entity\EntityAccessControlHandler;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * Spaces are public furniture: anyone who may see the schedule may see them.
 */
final class SpaceAccessControlHandler extends EntityAccessControlHandler {

  /**
   * {@inheritdoc}
   */
  protected function checkAccess(EntityInterface $entity, $operation, AccountInterface $account): AccessResultInterface {
    if ($operation === 'view') {
      return AccessResult::allowedIfHasPermission($account, 'view space schedule')
        ->orIf(AccessResult::allowedIfHasPermission($account, 'administer space booking'));
    }
    return AccessResult::allowedIfHasPermission($account, 'administer space booking');
  }

  /**
   * {@inheritdoc}
   */
  protected function checkCreateAccess(AccountInterface $account, array $context, $entity_bundle = NULL): AccessResultInterface {
    return AccessResult::allowedIfHasPermission($account, 'administer space booking');
  }

}
