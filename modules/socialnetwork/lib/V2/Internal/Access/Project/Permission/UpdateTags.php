<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Access\Project\Permission;

use Attribute;
use Bitrix\Socialnetwork\UserToGroupTable;
use Bitrix\Socialnetwork\V2\Internal\Access\AttributeAccessInterface;
use Bitrix\Socialnetwork\V2\Internal\Access\Context\Context;
use Bitrix\Socialnetwork\V2\Internal\Entity\EntityInterface;

/**
 * Permission check for tag editing.
 * Works for ALL workgroup types (collabs, projects, scrums, groups).
 * Allowed for: owner (A) and moderator (E).
 */
#[Attribute(Attribute::TARGET_PARAMETER)]
class UpdateTags implements AttributeAccessInterface
{
	public function check(EntityInterface $entity, Context $context, array $parameters = []): bool
	{
		$entityId = (int)$entity->getId();
		$userId = $context->getUserId();

		if ($entityId <= 0 || $userId <= 0)
		{
			return false;
		}

		$relation = UserToGroupTable::getList([
			'filter' => [
				'=USER_ID' => $userId,
				'=GROUP_ID' => $entityId,
			],
			'select' => ['ROLE'],
			'limit' => 1,
		])->fetch();

		if (!$relation)
		{
			return false;
		}

		return in_array(
			$relation['ROLE'],
			[UserToGroupTable::ROLE_OWNER, UserToGroupTable::ROLE_MODERATOR],
			true,
		);
	}
}
