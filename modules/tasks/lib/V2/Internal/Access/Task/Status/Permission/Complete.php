<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Access\Task\Status\Permission;

use Attribute;
use Bitrix\Main\Access\AccessibleController;
use Bitrix\Tasks\Access\ActionDictionary;
use Bitrix\Tasks\Access\Model\TaskModel;
use Bitrix\Tasks\Internals\Task\Status;
use Bitrix\Tasks\V2\Internal\Access\AccessUserErrorInterface;
use Bitrix\Tasks\V2\Internal\Access\AccessUserErrorTrait;
use Bitrix\Tasks\V2\Internal\Access\AttributeAccessInterface;
use Bitrix\Tasks\V2\Internal\Access\Factory\AccessControllerTrait;
use Bitrix\Tasks\V2\Internal\Access\Factory\Type;
use Bitrix\Tasks\V2\Internal\Access\Task\Status\CompletionAccessRule;
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

		$result
			= $this->hasFinalStatus($before)
				? $accessController->check(ActionDictionary::ACTION_TASK_READ, $before)
				: $this->canFinalize($accessController, $before);

		if (!$result)
		{
			$this->resolveUserError($accessController);
		}

		return $result;
	}

	private function canFinalize(AccessibleController $accessController, ?TaskModel $before): bool
	{
		return CompletionAccessRule::isSatisfiedBy(
			static fn (string $action): bool => $accessController->check($action, $before),
		);
	}

	private function hasFinalStatus(?TaskModel $before): bool
	{
		return in_array((int)$before?->getStatus(), Status::getCompletedStatuses(), true);
	}
}
