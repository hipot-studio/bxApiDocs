<?php
/**
 * Bitrix Framework
 * @package bitrix
 * @subpackage tasks
 * @copyright 2001-2021 Bitrix
 */

namespace Bitrix\Tasks\Access\Rule;

use Bitrix\Main\Loader;
use Bitrix\Tasks\Access\Model\TaskModel;
use Bitrix\Tasks\Access\Permission\PermissionDictionary;
use Bitrix\Main\Access\AccessibleItem;
use Bitrix\Tasks\Access\Role\RoleDictionary;
use Bitrix\Tasks\Access\Rule\Traits\AssignTrait;
use Bitrix\Tasks\Access\Rule\Traits\FlowTrait;
use Bitrix\Tasks\Flow\Access\FlowModel;
use Bitrix\Tasks\Flow\Option\OptionDictionary;
use Bitrix\Tasks\Flow\Option\OptionService;

class TaskChangeResponsibleRule extends \Bitrix\Main\Access\Rule\AbstractRule
{
	use AssignTrait, FlowTrait;

	public function execute(AccessibleItem $task = null, $params = null): bool
	{
		if (!$task)
		{
			$this->controller->addError(static::class, 'Incorrect task');
			return false;
		}

		if ($this->user->isAdmin())
		{
			return true;
		}

		if (!$this->canChangeResponsible($task))
		{
			$this->controller->addError(static::class, 'Access to change responsible denied');
			return false;
		}

		$oldTask = $task;
		$newTask = $task;
		if ($params instanceof TaskModel)
		{
			$newTask = $params;
		}

		if (!$this->canAssignTask($oldTask, RoleDictionary::ROLE_RESPONSIBLE, $newTask, [$this->user->getUserId()]))
		{
			$this->controller->addError(static::class, 'Access to assign responsible denied');
			return false;
		}

		return true;
	}

	private function canChangeResponsible(AccessibleItem $task): bool
	{
		if (!$task->getId())
		{
			return true;
		}

		if (
			$task->isMember($this->user->getUserId(), RoleDictionary::ROLE_DIRECTOR)
		)
		{
			return true;
		}

		if (
			$task->isMember($this->user->getUserId(), RoleDictionary::ROLE_RESPONSIBLE)
			&& $this->user->getPermission(PermissionDictionary::TASK_ASSIGNEE_CHANGE_RESPONSIBLE)
		)
		{
			return true;
		}

		if (
			$task->getGroupId()
			&& Loader::includeModule("socialnetwork")
			&& \Bitrix\Socialnetwork\Internals\Registry\FeaturePermRegistry::getInstance()->get(
				$task->getGroupId(),
				'tasks',
				'edit_tasks',
				$this->user->getUserId()
			)
		)
		{
			return true;
		}

		if (array_intersect($task->getMembers(RoleDictionary::ROLE_DIRECTOR), $this->user->getAllSubordinates()))
		{
			return true;
		}

		if ($task->getFlowId())
		{
			$option = OptionService::getInstance()->getOption($task->getFlowId(), OptionDictionary::MANUAL_DISTRIBUTOR_ID->value);

			if ($option?->getValue() > 0 && (int)$option->getValue() === $this->user->getUserId())
			{
				return true;
			}
		}

		if ($this->canUserUpdateTaskAssigneeInFlow($task, $this->user->getUserId()))
		{
			return true;
		}

		return false;
	}
}
