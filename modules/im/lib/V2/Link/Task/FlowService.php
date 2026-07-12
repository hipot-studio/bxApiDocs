<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Link\Task;

use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Chat\CollabChat;
use Bitrix\Im\V2\Common\ContextCustomer;
use Bitrix\Im\V2\Result;
use Bitrix\Main\Error;

class FlowService
{
	use ContextCustomer;

	public function prepareDataForCreateForm(Chat $chat): Result
	{
		$result = new Result();

		if (!$chat instanceof CollabChat)
		{
			return $result->addError(new Error('Chat type is not supported', 'CHAT_TYPE_NOT_SUPPORTED'));
		}

		$groupId = (int)$chat->getEntityId();

		if ($groupId <= 0)
		{
			return $result->addError(new Error('Group was not found', 'GROUP_WAS_NOT_FOUND'));
		}

		return $result->setResult([
			'groupId' => $groupId,
		]);
	}
}
