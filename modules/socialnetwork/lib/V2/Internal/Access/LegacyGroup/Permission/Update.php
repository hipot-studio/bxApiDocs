<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Access\LegacyGroup\Permission;

use Attribute;
use Bitrix\Socialnetwork\V2\Internal\Access\AttributeAccessInterface;
use Bitrix\Socialnetwork\V2\Internal\Access\Context\Context;
use Bitrix\Socialnetwork\V2\Internal\DI\Container;
use Bitrix\Socialnetwork\V2\Internal\Entity\EntityInterface;

/**
 * Transitional permission for legacy group types in grid context.
 *
 * After all groups are converted to collabs, delete this class.
 */
#[Attribute(Attribute::TARGET_PARAMETER)]
class Update implements AttributeAccessInterface
{
	public function check(EntityInterface $entity, Context $context, array $parameters = []): bool
	{
		$entityId = (int)$entity->getId();
		if ($entityId <= 0)
		{
			return false;
		}

		return Container::getInstance()
			->getLegacyGroupAccessService()
			->canUpdate($context->getUserId(), $entityId)
		;
	}
}
