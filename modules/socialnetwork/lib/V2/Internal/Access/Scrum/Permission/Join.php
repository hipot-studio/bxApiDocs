<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Access\Scrum\Permission;

use Attribute;
use Bitrix\Socialnetwork\V2\Internal\Access\AttributeAccessInterface;
use Bitrix\Socialnetwork\V2\Internal\Access\Context\Context;
use Bitrix\Socialnetwork\V2\Internal\DI\Container;
use Bitrix\Socialnetwork\V2\Internal\Entity\EntityInterface;

#[Attribute(Attribute::TARGET_PARAMETER)]
class Join implements AttributeAccessInterface
{
	public function check(EntityInterface $entity, Context $context, array $parameters = []): bool
	{
		$entityId = (int)$entity->getId();
		if ($entityId <= 0)
		{
			return false;
		}

		return Container::getInstance()
			->getScrumAccessService()
			->canJoin($context->getUserId(), $entityId)
		;
	}
}
