<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Counter\Collector;

use Bitrix\Tasks\Internals\Counter;
use Bitrix\Tasks\Internals\Counter\CounterDictionary;
use Bitrix\Tasks\V2\Internal\DI\Container;
use Bitrix\Tasks\V2\Internal\Integration\Im\Service\ImMessageReadServiceDelegate;

class GarbageCollector extends Counter\Event\GarbageCollector
{
	/**
	 * Mark IM Messages as read by particular User.
	 *
	 * @param int $userId 
	 * @param int[] $topicIds Not used. Keep for compatibility.
	 * @param int[] $taskIds 
	 * @return void 
	 * 
	 * @see \Bitrix\Im\V2\Message\ReadService::readAllInChat()
	 */
	public function readTopics(int $userId, array $topicIds, array $taskIds): void
	{
		$chatIds = Container::getInstance()->getChatRepository()->findChatIdsByTaskIds($taskIds);

		if (empty($chatIds))
		{
			return;
		}

		$readerService = new ImMessageReadServiceDelegate($userId);

		foreach ($chatIds as $chatId)
		{
			$readerService->readAllInChat($chatId);
		}

		Container::getInstance()->getCounterRepository()->deleteByUserAndTaskAndType($userId, $taskIds, CounterDictionary::COUNTER_MENTIONED);
	}
}
