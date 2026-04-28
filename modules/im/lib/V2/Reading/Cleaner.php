<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Reading;

use Bitrix\Im\V2\MessageCollection;
use Bitrix\Im\V2\Reading\Counter\CountersUpdater;
use Bitrix\Im\V2\Reading\View\ViewUpdater;

class Cleaner
{
	public function __construct(
		private readonly CountersUpdater $countersUpdater,
		private readonly ViewUpdater $viewUpdater,
	) {}

	public function onDeleteMessages(MessageCollection $messages, array $affectedUsers): void
	{
		$this->countersUpdater->delete()->byMessages($messages)->forAllUsers($affectedUsers)->execute();
		$this->viewUpdater->deleteByMessagesIdsForAll($messages->getIds());
	}

	public function onCleanHistory(int $chatId, int $userId): void
	{
		$this->countersUpdater->delete()->byChat($chatId)->forUser($userId)->execute();
		$this->viewUpdater->deleteByChatId($chatId, $userId);
	}
}
