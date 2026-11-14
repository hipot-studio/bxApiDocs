<?php

namespace Bitrix\Im\V2\Sync\Entity;

use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Sync\Entities;
use Bitrix\Im\V2\Sync\Event;

class EntityFactory
{
	public function createEntities(array $logEvents, int $currentUserId = 0): Entities
	{
		$messages = new Messages();
		$chats = new Chats();
		$pins = new PinMessages();
		$personalPins = new PersonalPinMessages();
		$users = new Users();

		$this->preloadGlobalCleanupRelations($logEvents, $currentUserId);

		foreach ($logEvents as $logEvent)
		{
			$event = Event::initByEntity($logEvent);

			if ((int)($logEvent['USER_ID'] ?? 0) === 0 && !$this->isGlobalCleanupReadable($event, $currentUserId))
			{
				continue;
			}

			switch ($event->entityType)
			{
				case Event::CHAT_ENTITY:
				case Event::CHAT_CLEANUP_ENTITY:
					$chats->add($event);
					break;
				case Event::MESSAGE_ENTITY:
				case Event::UPDATED_MESSAGE_ENTITY:
					$messages->add($event);
					break;
				case Event::PIN_MESSAGE_ENTITY:
					$pins->add($event);
					break;
				case Event::PERSONAL_PIN_MESSAGE_ENTITY:
					$personalPins->add($event);
					break;
				case Event::USER_ENTITY:
					$users->add($event);
					break;
			}
		}

		$dialogIds = new DialogIds($chats);

		return new Entities($chats, $messages, $pins, $dialogIds, $users, $currentUserId, $personalPins);
	}

	/**
	 * The check below asks every chat named by a global cleanup row for itself and for the relation of the
	 * reader, and a page of the journal carries as many of those rows as the caller asked for - up to ten
	 * thousand, naming a chat each. Two selections ahead of the walk answer all of them: the chats
	 * themselves, and the relation {@see Chat::checkAccess()} looks for, the absence of one included. After
	 * them no check reaches the database on its own. A chat named twice is the same instance either way,
	 * and keeps the answer it has already given.
	 *
	 * Only the chats whose relations the batch reads the same way a single chat would: a chat under a
	 * parent is read against the chain above it, and an external chat against a rule of its own, neither
	 * of which a selection by chat ids carries. Those keep the reading they have - the batch is a
	 * prefetch, and it must not answer differently from the check it saves.
	 */
	private function preloadGlobalCleanupRelations(array $logEvents, int $currentUserId): void
	{
		if ($currentUserId <= 0)
		{
			return;
		}

		$chatIds = [];

		foreach ($logEvents as $logEvent)
		{
			if (
				(int)($logEvent['USER_ID'] ?? 0) !== 0
				|| ($logEvent['ENTITY_TYPE'] ?? '') !== Event::CHAT_CLEANUP_ENTITY
			)
			{
				continue;
			}

			$chatId = (int)($logEvent['ENTITY_ID'] ?? 0);

			if ($chatId > 0)
			{
				$chatIds[$chatId] = $chatId;
			}
		}

		if (empty($chatIds))
		{
			return;
		}

		Chat::fillInstances($chatIds);

		$chats = [];

		foreach ($chatIds as $chatId)
		{
			$chat = Chat::getInstance($chatId);

			if (
				$chat instanceof Chat\NullChat
				|| $chat instanceof Chat\ExternalChat
				|| $chat->hasParent()
			)
			{
				continue;
			}

			$chats[$chatId] = $chat;
		}

		if ($chats !== [])
		{
			Chat::fillSelfRelations($chats, $currentUserId);
		}
	}

	/**
	 * A cleanup of an open chat is journalled once, with USER_ID = 0, because a reader without a relation
	 * has no row of their own. Every client reads that row, so the chat it names is let through only for
	 * the one the chat itself lets in; an unknown reader is turned away rather than trusted.
	 */
	private function isGlobalCleanupReadable(Event $event, int $currentUserId): bool
	{
		if ($event->entityType !== Event::CHAT_CLEANUP_ENTITY)
		{
			return true;
		}

		if ($currentUserId <= 0)
		{
			return false;
		}

		return Chat::getInstance($event->entityId)->checkAccess($currentUserId)->isSuccess();
	}
}
