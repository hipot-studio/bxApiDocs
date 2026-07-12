<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Access\Project\Permission;

use Attribute;
use Bitrix\Socialnetwork\Permission\GroupAccessController;
use Bitrix\Socialnetwork\Permission\GroupDictionary;
use Bitrix\Socialnetwork\V2\Internal\Access\AttributeAccessInterface;
use Bitrix\Socialnetwork\V2\Internal\Access\Context\Context;
use Bitrix\Socialnetwork\V2\Internal\Entity\EntityInterface;

/**
 * Permission for deleting user's own join request.
 * Uses GroupAccessController::DELETE_INCOMING_REQUEST
 * (no collab-specific analogue exists for this action).
 */
#[Attribute(Attribute::TARGET_PARAMETER)]
class DeleteIncomingRequest implements AttributeAccessInterface
{
	public function check(EntityInterface $entity, Context $context, array $parameters = []): bool
	{
		$entityId = (int)$entity->getId();
		$userId = $context->getUserId();

		if ($entityId <= 0 || $userId <= 0)
		{
			return false;
		}

		return GroupAccessController::can(
			$userId,
			GroupDictionary::DELETE_INCOMING_REQUEST,
			$entityId,
			['userId' => $userId],
		);
	}
}
