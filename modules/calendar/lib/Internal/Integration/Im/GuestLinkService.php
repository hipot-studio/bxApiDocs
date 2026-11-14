<?php

declare(strict_types=1);

namespace Bitrix\Calendar\Internal\Integration\Im;

use Bitrix\Im\Model\ChatTable;
use Bitrix\Im\V2\Application\Features;
use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Guest;
use Bitrix\Im\V2\SharingLink\Entity\LinkEntityType;
use Bitrix\Im\V2\SharingLink\GuestChatLink;
use Bitrix\Im\V2\SharingLink\SharingLinkError;
use Bitrix\Im\V2\SharingLink\SharingLinkFactory;
use Bitrix\Main\Loader;
use Bitrix\Main\Result;

/**
 * Calendar-side wrapper around the im guest chat link API.
 *
 * Every method guards the im module inclusion and degrades to false/null when
 * im is unavailable, so callers never need to touch Bitrix\Im\* directly.
 */
class GuestLinkService
{
	public function isAvailable(): bool
	{
		return Loader::includeModule('im');
	}

	public function isChatWithGuestsAvailable(?int $inviterId = null): bool
	{
		return $this->isAvailable() && Features::isChatWithGuestsAvailable($inviterId);
	}

	/**
	 * Whether the chat is the calendar chat bound to the given event.
	 *
	 * Calendar chats are created in a single place - Intranet\Secretary::createCalendarChat() -
	 * which stores ENTITY_TYPE = CCalendar::CALENDAR_CHAT_ENTITY_TYPE and ENTITY_ID = event id.
	 * A chat that fails this binding is somebody else's conversation, whatever the event
	 * happens to store in MEETING.CHAT_ID.
	 */
	public function isCalendarChatOfEvent(int $chatId, int $eventId): bool
	{
		return $this->getCalendarChatOfEvent($chatId, $eventId) !== null;
	}

	/**
	 * Read-only lookup of the active guest link url; never generates a new link.
	 */
	public function getActiveLinkUrl(int $chatId, int $authorId): ?string
	{
		if (!$this->isAvailable())
		{
			return null;
		}

		$link = SharingLinkFactory::getInstance()->getActiveIndividualLinkByEntityFields(
			LinkEntityType::GuestChat,
			(string)$chatId,
			$authorId,
		);

		if (!$link instanceof GuestChatLink)
		{
			return null;
		}

		return $link->getInviteUrl();
	}

	/**
	 * Returns the active guest link url, generating a new link when none exists.
	 */
	public function getOrCreateLinkUrl(int $chatId, int $authorId): ?string
	{
		if (!$this->isAvailable())
		{
			return null;
		}

		$chat = Chat::getInstance($chatId);
		if (!$chat->isExist())
		{
			return null;
		}

		$linkResult = Guest\GuestLinkService::getInstance()->getOrCreateLink(
			chat: $chat,
			authorId: $authorId,
		);

		if (!$linkResult->isSuccess())
		{
			return null;
		}

		/** @var GuestChatLink $guestChatLink */
		$guestChatLink = $linkResult->getResult();

		return $guestChatLink->getInviteUrl();
	}

	/**
	 * Finds the calendar chat of the event by its entity binding and returns it with its author.
	 *
	 * The search runs backwards - from the event id to the chat - so it also works once the
	 * event row itself is gone: ENTITY_TYPE/ENTITY_ID written by Intranet\Secretary is the side
	 * of the pair that outlives the event, while MEETING.CHAT_ID dies with it.
	 *
	 * The author is the organizer the chat was created for and the owner of the individual
	 * guest link; a chat without one has no such link to speak of.
	 *
	 * @return array{chatId: int, authorId: int}|null
	 */
	public function findCalendarChatOfEvent(int $eventId): ?array
	{
		if ($eventId <= 0)
		{
			return null;
		}

		return $this->findCalendarChatsOfEvents([$eventId])[$eventId][0] ?? null;
	}

	/**
	 * @param int[] $eventIds
	 * @return array<int, array<int, array{chatId: int, authorId: int}>>|null null when im is unavailable
	 */
	public function findCalendarChatsOfEvents(array $eventIds): ?array
	{
		if (!$this->isAvailable())
		{
			return null;
		}

		$eventIds = array_values(array_unique(array_filter(
			array_map('intval', $eventIds),
			static fn(int $eventId): bool => $eventId > 0,
		)));
		if ($eventIds === [])
		{
			return [];
		}

		$rows = ChatTable::query()
			->setSelect(['ID', 'AUTHOR_ID', 'ENTITY_ID'])
			->where('ENTITY_TYPE', \CCalendar::CALENDAR_CHAT_ENTITY_TYPE)
			->whereIn('ENTITY_ID', array_map('strval', $eventIds))
			->exec()
		;

		$chats = [];
		while ($row = $rows->fetch())
		{
			$eventId = (int)$row['ENTITY_ID'];
			$chatId = (int)$row['ID'];
			$authorId = (int)$row['AUTHOR_ID'];
			if ($eventId > 0 && $chatId > 0 && $authorId > 0)
			{
				$chats[$eventId][] = [
					'chatId' => $chatId,
					'authorId' => $authorId,
				];
			}
		}

		return $chats;
	}

	public function getCalendarChatAuthorId(int $chatId, int $eventId): int
	{
		$chat = $this->getCalendarChatOfEvent($chatId, $eventId);

		return (int)($chat?->getAuthorId() ?? 0);
	}

	/**
	 * @return array{eventId: int, authorId: int}|null
	 */
	public function getCalendarChatBinding(int $chatId): ?array
	{
		if (!$this->isAvailable() || $chatId <= 0)
		{
			return null;
		}

		$chat = Chat::getInstance($chatId);
		if (
			!$chat->isExist()
			|| $chat->getEntityType() !== \CCalendar::CALENDAR_CHAT_ENTITY_TYPE
		)
		{
			return null;
		}

		$eventId = (int)$chat->getEntityId();
		$authorId = (int)$chat->getAuthorId();

		return $eventId > 0 && $authorId > 0
			? ['eventId' => $eventId, 'authorId' => $authorId]
			: null;
	}

	/**
	 * @param int[] $chatIds
	 * @return array<int, array{eventId: int, authorId: int}>|null null when im is unavailable
	 */
	public function getCalendarChatBindings(array $chatIds): ?array
	{
		if (!$this->isAvailable())
		{
			return null;
		}

		$chatIds = array_values(array_unique(array_filter(
			array_map('intval', $chatIds),
			static fn(int $chatId): bool => $chatId > 0,
		)));
		if ($chatIds === [])
		{
			return [];
		}

		$rows = ChatTable::query()
			->setSelect(['ID', 'AUTHOR_ID', 'ENTITY_ID'])
			->whereIn('ID', $chatIds)
			->where('ENTITY_TYPE', \CCalendar::CALENDAR_CHAT_ENTITY_TYPE)
			->exec()
		;

		$bindings = [];
		while ($row = $rows->fetch())
		{
			$chatId = (int)$row['ID'];
			$eventId = (int)$row['ENTITY_ID'];
			$authorId = (int)$row['AUTHOR_ID'];
			if ($chatId > 0 && $eventId > 0 && $authorId > 0)
			{
				$bindings[$chatId] = [
					'eventId' => $eventId,
					'authorId' => $authorId,
				];
			}
		}

		return $bindings;
	}

	/**
	 * Revokes the author's active guest link of the chat and kicks the guests who joined
	 * through it. Silent: no system message - the link is revoked by the system, not by a
	 * member of the chat.
	 *
	 * An absent link is a regular outcome (none was ever issued), not a failure. Same for an
	 * absent im module: without it there are no guest links at all.
	 */
	public function revokeLink(int $chatId, int $authorId): Result
	{
		$result = new Result();

		if (!$this->isAvailable() || $chatId <= 0 || $authorId <= 0)
		{
			return $result;
		}

		$chat = Chat::getInstance($chatId);
		if (!$chat->isExist())
		{
			return $result;
		}

		$activeLink = SharingLinkFactory::getInstance()->getActiveIndividualLinkByEntityFields(
			LinkEntityType::GuestChat,
			(string)$chatId,
			$authorId,
		);
		if (!$activeLink instanceof GuestChatLink)
		{
			return $result;
		}

		$revokeResult = Guest\GuestLinkService::getInstance()->revokeLink(
			chat: $chat,
			authorId: $authorId,
			withMessage: false,
		);

		if ($revokeResult->isSuccess() || $this->isLinkNotFound($revokeResult))
		{
			return $result;
		}

		return $result->addErrors($revokeResult->getErrors());
	}

	private function isLinkNotFound(Result $result): bool
	{
		$errors = $result->getErrors();

		foreach ($errors as $error)
		{
			if ($error->getCode() !== SharingLinkError::NOT_FOUND)
			{
				return false;
			}
		}

		return $errors !== [];
	}

	private function getCalendarChatOfEvent(int $chatId, int $eventId): ?Chat
	{
		if (!$this->isAvailable() || $chatId <= 0 || $eventId <= 0)
		{
			return null;
		}

		$chat = Chat::getInstance($chatId);

		return $chat->isExist()
			&& $chat->getEntityType() === \CCalendar::CALENDAR_CHAT_ENTITY_TYPE
			&& (int)$chat->getEntityId() === $eventId
				? $chat
				: null;
	}
}
