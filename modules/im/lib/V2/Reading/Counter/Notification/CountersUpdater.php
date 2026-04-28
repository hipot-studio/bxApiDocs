<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Reading\Counter\Notification;

use Bitrix\Im\Model\MessageUnreadTable;
use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Message\Counter\CounterOverflowService;
use Bitrix\Im\V2\MessageCollection;
use Bitrix\Im\V2\Reading\Counter\Internal\CountersCache;
use Bitrix\Im\V2\Reading\Counter\Updater\Delete\ScopeStep;

class CountersUpdater
{
	public function __construct(
		protected readonly CountersCache $cache,
		protected readonly CounterOverflowService $overflowService,
	) {}

	public function add(MessageCollection $messages, int $userId): void
	{
		MessageUnreadTable::multiplyInsertWithoutDuplicate($this->getInsertData($messages, $userId));
		$this->cache->remove($userId);
	}

	public function delete(): ScopeStep
	{
		return new ScopeStep($this->cache, $this->overflowService);
	}

	protected function getInsertData(MessageCollection $messages, int $userId): array
	{
		$result = [];
		foreach ($messages as $message)
		{
			$result[] = [
				'MESSAGE_ID' => $message->getMessageId(),
				'CHAT_ID' => $message->getChatId(),
				'USER_ID' => $userId,
				'CHAT_TYPE' => Chat::IM_TYPE_SYSTEM,
				'IS_MUTED' => 'N',
				'PARENT_ID' => 0,
			];
		}

		return $result;
	}
}
