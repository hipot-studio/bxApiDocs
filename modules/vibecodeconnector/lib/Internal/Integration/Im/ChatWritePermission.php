<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Integration\Im;

use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Chat\Update\UpdateFields;
use Bitrix\Im\V2\Chat\Update\UpdateService;

final class ChatWritePermission
{
	public function isAllowedForMembers(Chat $chat): bool
	{
		return $chat->getManageMessages() !== Chat::MANAGE_RIGHTS_OWNER;
	}

	/**
	 * @return bool whether the chat carries the requested value afterwards
	 */
	public function setAllowedForMembers(Chat $chat, int $contextUserId, bool $allowed): bool
	{
		$target = $allowed ? Chat::MANAGE_RIGHTS_MEMBER : Chat::MANAGE_RIGHTS_OWNER;
		if ($chat->getManageMessages() === $target)
		{
			return true;
		}

		$result = (new UpdateService(
			$chat->withContextUser($contextUserId),
			new UpdateFields(manageMessages: $target),
		))->updateChat();

		return $result->isSuccess();
	}
}
