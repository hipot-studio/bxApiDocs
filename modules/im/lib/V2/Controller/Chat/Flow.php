<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Controller\Chat;

use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Controller\BaseController;
use Bitrix\Im\V2\Link\Task\FlowService;

class Flow extends BaseController
{
	/**
	 * @restMethod im.v2.Chat.Flow.prepare
	 */
	public function prepareAction(?Chat $chat = null): ?array
	{
		if (!isset($chat))
		{
			$this->addError(new Chat\ChatError(Chat\ChatError::NOT_FOUND));

			return null;
		}

		$result = (new FlowService())->prepareDataForCreateForm($chat);

		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return null;
		}

		return $result->getResult();
	}
}
