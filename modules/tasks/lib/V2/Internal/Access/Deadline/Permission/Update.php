<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Access\Deadline\Permission;

use Attribute;
use Bitrix\Tasks\Access\ActionDictionary;
use Bitrix\Tasks\Access\TaskAccessController;
use Bitrix\Tasks\V2\Internal\Access\AccessUserErrorInterface;
use Bitrix\Tasks\V2\Internal\Access\AccessUserErrorTrait;
use Bitrix\Tasks\V2\Internal\Access\Adapter\TaskModelAdapter;
use Bitrix\Tasks\V2\Internal\Access\AttributeAccessInterface;
use Bitrix\Tasks\V2\Internal\Access\Factory\AccessControllerTrait;
use Bitrix\Tasks\V2\Internal\Entity;
use Bitrix\Tasks\V2\Internal\Access\Context\Context;

#[Attribute(Attribute::TARGET_PARAMETER)]
class Update implements AttributeAccessInterface, AccessUserErrorInterface
{
	use AccessControllerTrait;
	use AccessUserErrorTrait;

	public function check(Entity\EntityInterface $entity, Context $context, array $parameters = []): bool
	{
		$accessController = TaskAccessController::getInstance($context->getUserId());

		$adapter = $this->getAdapter($entity);
		$before = $adapter->create();

		$result = $accessController->check(ActionDictionary::ACTION_TASK_DEADLINE, $before);
		if (!$result)
		{
			$this->resolveUserError($accessController);
		}

		return $result;
	}
}
