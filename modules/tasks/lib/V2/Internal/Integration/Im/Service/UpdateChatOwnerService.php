<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\Im\Service;

use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Chat\Update\UpdateFields;
use Bitrix\Im\V2\Chat\Update\UpdateService;
use Bitrix\Main\Loader;

class UpdateChatOwnerService
{
	public function handle(int $chatId, int $newOwnerId, ?int $previousOwnerId = null): void
	{
		if (!Loader::includeModule('im'))
		{
			return;
		}

		$chat = Chat::getInstance($chatId);

		if (null === $chat)
		{
			return;
		}

		$fields = [];
		$fields['OWNER_ID'] = $newOwnerId;
		if (null !== $previousOwnerId)
		{
			$fields['DELETED_MANAGERS'] = [$previousOwnerId];
		}

		$service = new UpdateService($chat, UpdateFields::create($fields));
		$service->updateChat();
	}
}
