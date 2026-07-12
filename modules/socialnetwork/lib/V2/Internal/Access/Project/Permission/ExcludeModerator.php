<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Access\Project\Permission;

use Attribute;
use Bitrix\Socialnetwork\V2\Internal\Access\AccessUserErrorInterface;
use Bitrix\Socialnetwork\V2\Internal\Access\AccessUserErrorTrait;
use Bitrix\Socialnetwork\V2\Internal\Access\AttributeAccessInterface;
use Bitrix\Socialnetwork\V2\Internal\Access\Context\Context;
use Bitrix\Socialnetwork\V2\Internal\DI\Container;
use Bitrix\Socialnetwork\V2\Internal\Entity\EntityInterface;

#[Attribute(Attribute::TARGET_PARAMETER)]
class ExcludeModerator implements AttributeAccessInterface, AccessUserErrorInterface
{
	use AccessUserErrorTrait;

	public function check(EntityInterface $entity, Context $context, array $parameters = []): bool
	{
		$this->clearUserError();

		$entityId = (int)$entity->getId();
		if ($entityId <= 0)
		{
			return false;
		}

		$projectData = $entity->toArray();

		$service = Container::getInstance()->getProjectAccessService();
		$result = $service->canExcludeModerator($context->getUserId(), $entityId, $projectData);
		if (!$result)
		{
			$this->setUserError($service->getUserError());
		}

		return $result;
	}
}
