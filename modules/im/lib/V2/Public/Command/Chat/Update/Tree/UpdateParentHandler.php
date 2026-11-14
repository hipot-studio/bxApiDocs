<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Public\Command\Chat\Update\Tree;

use Bitrix\Im\Model\ChatTable;
use Bitrix\Im\Model\MessageUnreadTable;
use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Integration\Search\IndexService;
use Bitrix\Im\V2\Result;

final class UpdateParentHandler
{
	public function __construct(
		private readonly IndexService $indexService,
	)
	{}

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

		// the parent answers for the reach of the shared access code of the search index while the type of
		// a chat needs membership in it, so a chat hung elsewhere keeps a code the messenger no longer gives
		$this->indexService->onChatReparented($targetChatIds);

		return new Result();
	}
}
