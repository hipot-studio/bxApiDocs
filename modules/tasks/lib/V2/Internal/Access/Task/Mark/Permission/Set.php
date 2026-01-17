<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Access\Task\Mark\Permission;

use Attribute;
use Bitrix\Tasks\Access\ActionDictionary;
use Bitrix\Tasks\V2\Internal\Access\AccessUserErrorInterface;
use Bitrix\Tasks\V2\Internal\Access\AccessUserErrorTrait;
use Bitrix\Tasks\V2\Internal\Access\AttributeAccessInterface;
use Bitrix\Tasks\V2\Internal\Access\Context\Context;
use Bitrix\Tasks\V2\Internal\Access\Factory\AccessControllerTrait;
use Bitrix\Tasks\V2\Internal\Access\Factory\Type;
use Bitrix\Tasks\V2\Internal\Entity;

#[Attribute(Attribute::TARGET_PARAMETER)]
class Set implements AttributeAccessInterface, AccessUserErrorInterface
{
	use AccessUserErrorTrait;
	use AccessControllerTrait;

	public function check(Entity\EntityInterface $entity, Context $context, array $parameters = []): bool
	{
		if (!$entity instanceof Entity\Task)
		{
			return false;
		}

		$accessController = $this->getAccessController(Type::Task, $context);
		$adapter = $this->getAdapter($entity);

		$before = $adapter->create();
		$after = $adapter->transform($entity);

		$result = $accessController->check(ActionDictionary::ACTION_TASK_RATE, $before, $after);
		if (!$result)
		{
			$this->resolveUserError($accessController);
		}

		return $result;
	}
}
