<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Access\Task\Status\Permission;

use Attribute;
use Bitrix\Tasks\Access\ActionDictionary;
use Bitrix\Tasks\V2\Internal\Access\AccessUserErrorInterface;
use Bitrix\Tasks\V2\Internal\Access\AccessUserErrorTrait;
use Bitrix\Tasks\V2\Internal\Access\AttributeAccessInterface;
use Bitrix\Tasks\V2\Internal\Access\Factory\AccessControllerTrait;
use Bitrix\Tasks\V2\Internal\Access\Factory\Type;
use Bitrix\Tasks\V2\Internal\Entity;
use Bitrix\Tasks\V2\Internal\Access\Context\Context;

#[Attribute(Attribute::TARGET_PARAMETER)]
class Complete implements AttributeAccessInterface, AccessUserErrorInterface
{
	use AccessControllerTrait;
	use AccessUserErrorTrait;

	public function check(Entity\EntityInterface $entity, Context $context, array $parameters = []): bool
	{
		$accessController = $this->getAccessController(Type::Task, $context);
		$adapter = $this->getAdapter($entity);

		$before = $adapter->create();

		$result = $accessController->check(ActionDictionary::ACTION_TASK_COMPLETE, $before)
			&& $accessController->check(ActionDictionary::ACTION_TASK_COMPLETE_RESULT, $before);

		if (!$result)
		{
			$this->resolveUserError($accessController);
		}

		return $result;
	}
}
