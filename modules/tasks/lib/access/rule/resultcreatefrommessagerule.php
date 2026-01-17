<?php

namespace Bitrix\Tasks\Access\Rule;

use Bitrix\Main\Access\Rule\AbstractRule;
use Bitrix\Main\Error;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Access\AccessibleItem;
use Bitrix\Tasks\Access\ActionDictionary;
use Bitrix\Tasks\Access\Model\ResultModel;
use Bitrix\Tasks\Access\ResultAccessController;
use Bitrix\Tasks\Access\TaskAccessController;

/**
 * @property ResultAccessController $controller
 */
class ResultCreateFromMessageRule extends AbstractRule
{
	public function execute(AccessibleItem $item = null, $params = null): bool
	{
		if (!Loader::includeModule('im'))
		{
			$this->controller->addError(static::class, 'im is not installed');

			return false;
		}

		if (!$item instanceof ResultModel)
		{
			$this->controller->addError(static::class, 'Item must be an instance of ResultModel');

			return false;
		}

		$chat = $item->getChat();
		if ($chat === null)
		{
			$this->controller->addError(static::class, 'Chat is not found');
			$this->controller->addUserError(new Error(Loc::getMessage('TASKS_RESULT_CREATE_FROM_MESSAGE_RULE_CHAT_NOT_FOUND')));

			return false;
		}

		if (!$chat->isTaskChat())
		{
			$this->controller->addError(static::class, 'Chat is not a task chat');
			$this->controller->addUserError(new Error(Loc::getMessage('TASKS_RESULT_CREATE_FROM_MESSAGE_RULE_CHAT_NOT_TASK_CHAT')));

			return false;
		}

		$taskId = $chat->entityId;
		if ($taskId === null)
		{
			$this->controller->addError(static::class, 'Task is not found in chat');
			$this->controller->addUserError(new Error(Loc::getMessage('TASKS_RESULT_CREATE_FROM_MESSAGE_RULE_TASK_ID_NOT_FOUND')));

			return false;
		}

		$accessController = TaskAccessController::getInstance($this->user->getUserId());
		if (!$accessController->checkByItemId(ActionDictionary::ACTION_TASK_READ, $taskId))
		{
			$this->controller->addError(static::class, 'Access to task is denied');
			$this->controller->addUserError(new Error(Loc::getMessage('TASKS_RESULT_CREATE_FROM_MESSAGE_RULE_ACCESS_TO_TASK_DENIED')));

			return false;
		}

		return true;
	}
}
