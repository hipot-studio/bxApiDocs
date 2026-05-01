<?php

/**
 * Bitrix Framework
 * @package bitrix
 * @subpackage tasks
 * @copyright 2001-2021 Bitrix
 */

namespace Bitrix\Tasks\Access\Rule;

use Bitrix\Main\Access\AccessibleItem;
use Bitrix\Main\Access\Rule\AbstractRule;
use Bitrix\Tasks\Access\ActionDictionary;
use Bitrix\Tasks\Access\Model\TaskModel;
use Bitrix\Tasks\Access\Model\UserModel;
use Bitrix\Tasks\Access\Role\RoleDictionary;
use Bitrix\Tasks\Access\Rule\Traits\AssignTrait;

class TaskChangeDirectorRule extends AbstractRule
{
	use AssignTrait;

	public function execute(?AccessibleItem $task = null, $params = null): bool
	{
		if (!$task)
		{
			$this->controller->addError(static::class, 'Incorrect task');

			return false;
		}

		if (!$this->checkParams($params))
		{
			$this->controller->addError(static::class, 'Incorrect params');

			return false;
		}

		if ($this->user->isAdmin())
		{
			return true;
		}

		/** @var TaskModel $newTask */
		$newTask = $params;

		if (!in_array($this->user->getUserId(), $newTask->getMembers(RoleDictionary::ROLE_RESPONSIBLE), true))
		{
			$this->controller->addError(static::class, 'Access to change director denied');

			return false;
		}

		$directorIds = $newTask->getMembers(RoleDictionary::ROLE_DIRECTOR);
		$user = UserModel::createFromId($this->user->getUserId());

		if (!$this->validateExtranetAccess($user, $directorIds))
		{
			return false;
		}

		// user can update task
		if ($this->controller->check(ActionDictionary::ACTION_TASK_EDIT, $task, $params))
		{
			return true;
		}

		$this->controller->addError(static::class, 'Access to change director denied');

		return false;
	}

	private function checkParams($params = null): bool
	{
		return is_object($params) && $params instanceof TaskModel;
	}

	private function validateExtranetAccess(UserModel $user, array $directorIds): bool
	{
		foreach ($directorIds as $directorId)
		{
			$director = UserModel::createFromId($directorId);

			if (
				($director->isExtranet() || $user->isExtranet())
				&& !$this->isMemberOfUserGroups($user->getUserId(), $directorId)
			)
			{
				$this->controller->addError(static::class, 'Access to change director denied');

				return false;
			}
		}

		return true;
	}
}
