<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Access\Project\Permission;

use Attribute;
use Bitrix\Socialnetwork\V2\Feature;
use Bitrix\Socialnetwork\V2\Internal\Access\AttributeAccessInterface;
use Bitrix\Socialnetwork\V2\Internal\Access\Context\Context;
use Bitrix\Socialnetwork\V2\Internal\DI\Container;
use Bitrix\Socialnetwork\V2\Internal\Entity\EntityInterface;

#[Attribute(Attribute::TARGET_PARAMETER)]
class Convert implements AttributeAccessInterface
{
	public function check(EntityInterface $entity, Context $context, array $parameters = []): bool
	{
		$entityId = (int)$entity->getId();
		if ($entityId <= 0)
		{
			return false;
		}

		if (!Feature::isNewProjectsOn())
		{
			return false;
		}

		return Container::getInstance()
			->getConvertProjectAccessService()
			->canConvert($entityId, $context->getUserId())
		;
	}
}
