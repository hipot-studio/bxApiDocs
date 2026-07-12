<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Public\Command\Chat\Update\Tree;

use Bitrix\Im\Model\ChatTable;
use Bitrix\Im\Model\MessageUnreadTable;
use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Result;

final class UpdateParentHandler
{
	public function __invoke(UpdateParentCommand $command): Result
	{
		$targetChatIds = $command->getTargetChatIds();
		$parentChatId = $command->getParentChatId();

		ChatTable::updateByFilter(
			['@ID' => $targetChatIds],
			['PARENT_ID' => $parentChatId],
		);
		MessageUnreadTable::updateByFilter(
			['@CHAT_ID' => $targetChatIds],
			['PARENT_ID' => $parentChatId],
		);

		foreach ($targetChatIds as $chatId)
		{
			Chat::cleanCache($chatId);
		}

		return new Result();
	}
}
