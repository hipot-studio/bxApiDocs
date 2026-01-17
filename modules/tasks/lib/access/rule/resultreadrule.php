<?php

declare(strict_types=1);

namespace Bitrix\Tasks\Access\Rule;

use Bitrix\Main\Access\AccessibleItem;
use Bitrix\Main\Access\Rule\AbstractRule;
use Bitrix\Tasks\Access\ActionDictionary;
use Bitrix\Tasks\Access\Model\ResultModel;
use Bitrix\Tasks\Access\ResultAccessController;
use Bitrix\Tasks\Access\TaskAccessController;

/**
 * @property ResultAccessController $controller
 */
class ResultReadRule extends AbstractRule
{
	public function execute(AccessibleItem $item = null, $params = null): bool
	{
		if (!$item instanceof ResultModel)
		{
			$this->controller->addError(static::class, 'Item must be an instance of ResultModel');

			return false;
		}

		$accessController = TaskAccessController::getInstance($this->user->getUserId());
		if (!$accessController->checkByItemId(ActionDictionary::ACTION_TASK_READ, $item->getTaskId()))
		{
			$this->controller->addError(static::class, 'Access denied for task read');

			return false;
		}

		return true;
	}
}
