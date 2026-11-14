<?php

namespace Bitrix\Socialnetwork\Controller;

use Bitrix\Im;
use Bitrix\Im\V2\Chat\Add\ChatCreateFields;
use Bitrix\Main\Loader;

class Chat extends Base
{
	public function createAction(array $userIds): ?int
	{
		if (!Loader::includeModule('im'))
		{
			return 0;
		}

		// The same gate as im.v2.Chat.add: the IM permission map forbids chat creation to extranet users
		$createFields = new ChatCreateFields(
			type: Im\V2\Chat::IM_TYPE_CHAT,
			authorId: $this->userId,
			users: $userIds,
		);
		$accessResult = $createFields->checkAccess($this->userId);
		if (!$accessResult->isSuccess())
		{
			$this->addErrors($accessResult->getErrors());

			return null;
		}

		$result = Im\V2\Chat\ChatFactory::getInstance()->addChat([
			'TYPE' => Im\V2\Chat::IM_TYPE_CHAT,
			'SKIP_ADD_MESSAGE' => 'Y',
			'AUTHOR_ID' => $this->userId,
			'USERS' => $userIds,
		]);
		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return null;
		}

		return (int)$result->getData()['RESULT']['CHAT_ID'];
	}
}
