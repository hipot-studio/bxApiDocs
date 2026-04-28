<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Reading\Infrastructure;

use Bitrix\Im\V2\Message;
use Bitrix\Im\V2\Reading\Counter\CountersService;
use Bitrix\Im\V2\Reading\Counter\CountersUpdater;
use Bitrix\Im\V2\Reading\Counter\Entity\UsersCounterMap;
use Bitrix\Im\V2\Reading\RecentReader;
use Bitrix\Im\V2\RelationCollection;

class NewMessageHandler
{
	public function __construct(
		private readonly CountersUpdater $counterUpdater,
		private readonly CountersService $countersService,
		private readonly RecentReader $recentReader,
	) {}

	/**
	 * Handles new message: adds unread for recipients, removes unread for author.
	 */
	public function handle(Message $message, RelationCollection $recipientRelations): void
	{
		// Add unread entries for recipients
		$this->counterUpdater->addForUsers($message, $recipientRelations);

		if (!$message->isSystem())
		{
			// Remove unread for author (author sent = author read up to this point)
			$this->counterUpdater
				->delete()
				->toMessage($message, $message->getAuthorId())
				->execute()
			;

			// Reset "read later" mark for author
			$this->recentReader->read($message->getAuthorId(), $message->getChatId());
		}
	}

	/**
	 * Handles new message and returns counters for push notifications.
	 */
	public function handleWithCounters(
		Message $message,
		RelationCollection $recipientRelations,
		array $userIdsForCounters,
	): UsersCounterMap
	{
		$this->handle($message, $recipientRelations);

		return $this->countersService->getForUsersWithOverflowTracking($message->getChatId(), $userIdsForCounters);
	}
}
