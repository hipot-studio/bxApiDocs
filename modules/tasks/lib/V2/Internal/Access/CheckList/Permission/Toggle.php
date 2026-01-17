<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Access\CheckList\Permission;

use Attribute;
use Bitrix\Tasks\Access\ActionDictionary;
use Bitrix\Tasks\Access\TaskAccessController;
use Bitrix\Tasks\V2\Internal\Access\AttributeAccessInterface;
use Bitrix\Tasks\V2\Internal\Access\Context\Context;
use Bitrix\Tasks\V2\Internal\DI\Container;
use Bitrix\Tasks\V2\Internal\Entity;

#[Attribute(Attribute::TARGET_PARAMETER)]
class Toggle implements AttributeAccessInterface
{
	public function check(Entity\EntityInterface $entity, Context $context, array $parameters = []): bool
	{
		$checkListMapper = Container::getInstance()->getCheckListMapper();
		$taskRepository = Container::getInstance()->getTaskRepository();

		if (!$entity->getId())
		{
			return false;
		}

		if ($entity->checklist === null)
		{
			return false;
		}

		$nodes = $checkListMapper->mapToNodes($entity->checklist);

		$canToggle = true;
		foreach ($nodes as $node)
		{
			if (
				!TaskAccessController::can(
					userId: $context->getUserId(),
					action: ActionDictionary::ACTION_CHECKLIST_TOGGLE,
					itemId: $entity->getId(),
					params: $node->toArray(),
				)
			)
			{
				$canToggle = false;

				break;
			}
		}

		if (!$canToggle)
		{
			return false;
		}

		$task = $taskRepository->getById($entity->getId());

		if (!$task)
		{
			return false;
		}

		return true;
	}
}