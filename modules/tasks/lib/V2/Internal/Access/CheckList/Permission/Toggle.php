<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Access\CheckList\Permission;

use Attribute;
use Bitrix\Tasks\Access\ActionDictionary;
use Bitrix\Tasks\Access\TaskAccessController;
use Bitrix\Tasks\CheckList\Task\TaskCheckListFacade;
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

		$nodes = $checkListMapper->mapToNodes($entity->checklist)->toArray();

		// the rule grants an item by its owner and refuses it by the task it belongs to, so both are
		// read from storage for all the nodes at once instead of a row per node
		$checkLists = TaskCheckListFacade::getAccessModelsByItemIds(array_column($nodes, 'ID'));

		$canToggle = true;
		foreach ($nodes as $node)
		{
			$itemId = (int)($node['ID'] ?? 0);

			// the rule decides by the stored row of the node, so a node naming no row of its own is
			// refused here and not by whatever the rule makes of an item that does not exist
			if ($itemId <= 0 || !isset($checkLists[$itemId]))
			{
				return false;
			}

			$checkList = $checkLists[$itemId];

			if (
				!TaskAccessController::can(
					userId: $context->getUserId(),
					action: ActionDictionary::ACTION_CHECKLIST_TOGGLE,
					itemId: $entity->getId(),
					params: $checkList,
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