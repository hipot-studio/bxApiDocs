<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Access\Project\Permission;

use Attribute;
use Bitrix\Socialnetwork\Permission\GroupAccessController;
use Bitrix\Socialnetwork\Permission\GroupDictionary;
use Bitrix\Socialnetwork\V2\Internal\Access\AttributeAccessInterface;
use Bitrix\Socialnetwork\V2\Internal\Access\Context\Context;
use Bitrix\Socialnetwork\V2\Internal\Entity\EntityInterface;

#[Attribute(Attribute::TARGET_PARAMETER)]
class DeleteOutgoingRequest implements AttributeAccessInterface
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
			GroupDictionary::DELETE_OUTGOING_REQUEST,
			$entityId,
			['userId' => $userId],
		);
	}
}
