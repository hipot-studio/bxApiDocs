<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Access\Task\Permission;

use Attribute;
use Bitrix\Main\Access\AccessibleController;
use Bitrix\Tasks\Access\ActionDictionary;
use Bitrix\Tasks\V2\Internal\Access\AccessUserErrorInterface;
use Bitrix\Tasks\V2\Internal\Access\AttributeAccessInterface;
use Bitrix\Tasks\V2\Internal\Access\ExistingEntityIdGuardTrait;
use Bitrix\Tasks\V2\Internal\Access\Factory\AccessControllerTrait;
use Bitrix\Tasks\V2\Internal\Access\Factory\Type;
use Bitrix\Tasks\V2\Internal\Entity\EntityInterface;
use Bitrix\Tasks\V2\Internal\Entity\EntityCollectionInterface;
use Bitrix\Tasks\V2\Internal\DI\Container;
use Bitrix\Tasks\V2\Internal\Access\Context\Context;

#[Attribute(Attribute::TARGET_PARAMETER)]
class Update implements AttributeAccessInterface, AccessUserErrorInterface
{
	use AccessControllerTrait;
	use ExistingEntityIdGuardTrait;

	public function check(EntityInterface|EntityCollectionInterface $entity, Context $context, array $parameters = []): bool
	{
		$accessController = $this->getAccessController(Type::Task, $context);

		if (!$this->guardExistingEntityId($entity))
		{
			return false;
		}

		if ($entity instanceof EntityInterface)
		{
			return $this->checkEntity($entity, $accessController);
		}

		Container::getInstance()->getTaskModelPreloader()->preload($context->getUserId(), $entity->getIds());

		foreach ($entity as $item)
		{
			if (!$this->checkEntity($item, $accessController))
			{
				return false;
			}
		}

		return true;
	}

	private function checkEntity(EntityInterface $entity, AccessibleController $accessController): bool
	{
		$adapter = $this->getAdapter($entity);

		$before = $adapter->create();
		$current = Container::getInstance()->getTaskRepository()->getById($entity->getId());
		if ($current === null)
		{
			return false;
		}

		$after = $adapter->transform($current);

		return $accessController->check(ActionDictionary::ACTION_TASK_SAVE, $before, $after);
	}
}
