<?php

namespace Bitrix\Tasks\Access\Rule;

use Bitrix\Main\Access\AccessibleItem;
use Bitrix\Main\Access\Rule\AbstractRule;
use Bitrix\Main\Error;
use Bitrix\Main\Localization\Loc;
use Bitrix\Tasks\Access\Model\TaskModel;
use Bitrix\Tasks\Access\Role\RoleDictionary;
use Bitrix\Tasks\Access\TaskAccessController;
use Bitrix\Tasks\Internals\Task\Result\ResultManager;
use Bitrix\Tasks\Internals\Task\Result\ResultTable;

/**
 * @property TaskAccessController $controller
 */
class TaskCompleteResultRule extends AbstractRule
{
	public function execute(AccessibleItem $item = null, $params = null): bool
	{
		if (!$item instanceof TaskModel)
		{
			$this->controller->addError(static::class, 'Incorrect task');

			return false;
		}

		if ($item->isClosed())
		{
			$this->controller->addError(static::class, 'Task already completed');

			return false;
		}

		if ($this->user->isAdmin())
		{
			return true;
		}

		if ($item->isMember($this->user->getUserId(), RoleDictionary::ROLE_DIRECTOR))
		{
			return true;
		}

		if (!$item->isResultRequired())
		{
			return true;
		}

		$lastResult = ResultManager::getLastResult($item->getId());

		if (!$lastResult || (int)$lastResult['STATUS'] !== ResultTable::STATUS_OPENED)
		{
			$this->controller->addUserError(new Error(Loc::getMessage('TASKS_TASK_COMPLETE_RESULT_RULE_NO_RESULT')));
			$this->controller->addError(static::class, 'Unable to complete task without result');

			return false;
		}

		return true;
	}
}
