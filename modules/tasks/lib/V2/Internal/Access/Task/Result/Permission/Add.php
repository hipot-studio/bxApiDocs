<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Access\Task\Result\Permission;

use Attribute;
use Bitrix\Main\Access\AccessibleController;
use Bitrix\Tasks\Access\ActionDictionary;
use Bitrix\Tasks\V2\Internal\Access\AccessUserErrorInterface;
use Bitrix\Tasks\V2\Internal\Access\AccessUserErrorTrait;
use Bitrix\Tasks\V2\Internal\Access\AttributeAccessInterface;
use Bitrix\Tasks\V2\Internal\Access\Factory\AccessControllerTrait;
use Bitrix\Tasks\V2\Internal\Access\Factory\Type;
use Bitrix\Tasks\V2\Internal\Entity;
use Bitrix\Tasks\V2\Internal\Access\Context\Context;

#[Attribute(Attribute::TARGET_PARAMETER)]
class Add implements AttributeAccessInterface, AccessUserErrorInterface
{
	use AccessControllerTrait;
	use AccessUserErrorTrait;

	public function check(Entity\EntityInterface|Entity\EntityCollectionInterface $entity, Context $context, array $parameters = []): bool
	{
		$accessController = $this->getAccessController(Type::Result, $context);

		if ($entity instanceof Entity\Result)
		{
			return $this->checkEntity($entity, $accessController);
		}

		if (!$entity instanceof Entity\EntityCollectionInterface)
		{
			return false;
		}

		foreach ($entity as $item)
		{
			$accessResult = $this->checkEntity($item, $accessController);
			if (!$accessResult)
			{
				$this->resolveUserError($accessController);

				return false;
			}
		}

		return true;
	}

	private function checkEntity(Entity\EntityInterface $entity, AccessibleController $accessController): bool
	{
		$adapter = $this->getAdapter($entity);

		$model = $adapter->transform();

		return $accessController->check(ActionDictionary::ACTION_RESULT_READ, $model);
	}
}
