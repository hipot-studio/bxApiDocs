<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Access\Task\Permission;

use Attribute;
use Bitrix\Tasks\Access\ActionDictionary;
use Bitrix\Tasks\V2\Internal\Access\AccessUserErrorInterface;
use Bitrix\Tasks\V2\Internal\Access\AttributeAccessInterface;
use Bitrix\Tasks\V2\Internal\Access\ExistingEntityIdGuardTrait;
use Bitrix\Tasks\V2\Internal\Access\Factory\AccessControllerTrait;
use Bitrix\Tasks\V2\Internal\Access\Factory\Type;
use Bitrix\Tasks\V2\Internal\Entity;
use Bitrix\Tasks\V2\Internal\Access\Context\Context;

#[Attribute(Attribute::TARGET_PARAMETER)]
class Delete implements AttributeAccessInterface, AccessUserErrorInterface
{
	use AccessControllerTrait;
	use ExistingEntityIdGuardTrait;

	public function check(Entity\EntityInterface $entity, Context $context, array $parameters = []): bool
	{
		$accessController = $this->getAccessController(Type::Task, $context);
		if (!$this->guardExistingEntityId($entity))
		{
			return false;
		}

		$adapter = $this->getAdapter($entity);

		$model = $adapter->create();

		return $accessController->check(ActionDictionary::ACTION_TASK_REMOVE, $model);
	}
}
