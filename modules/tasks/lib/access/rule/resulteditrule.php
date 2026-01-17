<?php

namespace Bitrix\Tasks\Access\Rule;

use Bitrix\Main\Access\Rule\AbstractRule;
use Bitrix\Main\Error;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Access\AccessibleItem;
use Bitrix\Tasks\Access\Model\ResultModel;
use Bitrix\Tasks\Access\ResultAccessController;

/**
 * @property ResultAccessController $controller
 */
class ResultEditRule extends AbstractRule
{
	public function execute(AccessibleItem $item = null, $params = null): bool
	{
		if (!$item instanceof ResultModel)
		{
			$this->controller->addError(static::class, 'Item must be an instance of ResultModel');

			return false;
		}

		if ($item->getCreatedBy() !== $this->user->getUserId())
		{
			$this->controller->addError(static::class, 'Access denied for task result edit: not the creator');
			$this->controller->addUserError(new Error(Loc::getMessage('TASKS_TASK_RESULT_EDIT_RULE_NOT_A_CREATOR')));

			return false;
		}

		return true;
	}
}
