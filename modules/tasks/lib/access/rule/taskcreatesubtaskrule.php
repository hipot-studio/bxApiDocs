<?php

declare(strict_types=1);

namespace Bitrix\Tasks\Access\Rule;

use Bitrix\Main\Access\AccessibleItem;
use Bitrix\Main\Access\Rule\AbstractRule;
use Bitrix\Tasks\Access\ActionDictionary;
use Bitrix\Tasks\Access\Model\TaskModel;
use Bitrix\Tasks\Access\Model\UserModel;
use Bitrix\Tasks\Access\TaskAccessController;

/**
 * @property UserModel $user
 * @property TaskAccessController $controller
 */
class TaskCreateSubTaskRule extends AbstractRule
{
	public function execute(AccessibleItem $item = null, $params = null): bool
	{
		if (!$item instanceof TaskModel)
		{
			$this->controller->addError(static::class, 'Incorrect task');

			return false;
		}

		if ($this->user->isEmail())
		{
			$this->controller->addError(static::class, 'Access to create subtask denied for email users');

			return false;
		}

		return $this->controller->check(ActionDictionary::ACTION_TASK_READ, $item, $params);
	}
}
