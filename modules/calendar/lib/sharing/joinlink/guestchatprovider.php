<?php

namespace Bitrix\Calendar\Sharing\JoinLink;

use Bitrix\Calendar\Core\Event\Tools\Dictionary;
use Bitrix\Calendar\Sharing\Link\EventLink;
use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Guest\GuestLinkService;
use Bitrix\Im\V2\SharingLink\GuestChatLink;
use Bitrix\Intranet\Secretary;
use Bitrix\Main\Error;

/**
 * Guest-chat join-link branch: reuses (or creates) the calendar-bound chat of
 * a shared event and returns a guest link for it. The branch is fail-soft - any
 * failure is reported as a failed {@see JoinLinkResult} and logged by
 * identifiers only; the event and its EventLink are left untouched.
 *
 * The gate checks that enable this branch live in {@see JoinLinkProvider}.
 */
class GuestChatProvider
{
	private const STEP_LOAD_EVENT = 'load_event';
	private const STEP_RESOLVE_CHAT = 'resolve_chat';
	private const STEP_GUEST_LINK = 'guest_link';
	private const STEP_EXCEPTION = 'exception';

	private JoinLinkLogger $logger;

	public function __construct(?JoinLinkLogger $logger = null)
	{
		$this->logger = $logger ?? new JoinLinkLogger();
	}

	public function resolve(EventLink $eventLink): JoinLinkResult
	{
		try
		{
			$chatIdResult = $this->doResolveChatId($eventLink);
			if (!$chatIdResult->isSuccess())
			{
				return $chatIdResult;
			}

			return $this->addGuestLink($eventLink, (int)$chatIdResult->getChatId());
		}
		catch (\Throwable $e)
		{
			return $this->fail($eventLink, self::STEP_EXCEPTION, $this->getShortClassName($e));
		}
	}

	/**
	 * Resolves only the guest-chat id (reusing or creating the chat) without
	 * generating a guest link.
	 */
	public function resolveChatId(EventLink $eventLink): JoinLinkResult
	{
		try
		{
			return $this->doResolveChatId($eventLink);
		}
		catch (\Throwable $e)
		{
			return $this->fail($eventLink, self::STEP_EXCEPTION, $this->getShortClassName($e));
		}
	}

	private function doResolveChatId(EventLink $eventLink): JoinLinkResult
	{
		$event = $this->loadEvent($eventLink->getEventId());
		if (!$this->isSupportedSharingEvent($event))
		{
			return $this->fail($eventLink, self::STEP_LOAD_EVENT, 'event_not_supported');
		}

		$chatId = $this->findChatId($event, (int)$eventLink->getOwnerId());
		if ($chatId <= 0)
		{
			return $this->fail($eventLink, self::STEP_RESOLVE_CHAT, 'chat_unavailable');
		}

		if (!Chat::getInstance($chatId)->isExist())
		{
			return $this->fail($eventLink, self::STEP_RESOLVE_CHAT, 'chat_not_found');
		}

		return JoinLinkResult::successChat(JoinLinkResult::BRANCH_GUEST_CHAT, $chatId);
	}

	private function addGuestLink(EventLink $eventLink, int $chatId): JoinLinkResult
	{
		$organizerId = (int)$eventLink->getOwnerId();
		$chat = Chat::getInstance($chatId);

		$linkResult = GuestLinkService::getInstance()->getOrCreateLink($chat, $organizerId);
		if (!$linkResult->isSuccess())
		{
			return $this->fail(
				$eventLink,
				self::STEP_GUEST_LINK,
				$this->getFirstErrorCode($linkResult->getErrors()),
				$linkResult->getErrors(),
			);
		}

		/** @var GuestChatLink $guestChatLink */
		$guestChatLink = $linkResult->getResult();

		return JoinLinkResult::success(
			JoinLinkResult::BRANCH_GUEST_CHAT,
			$guestChatLink->getInviteUrl(),
			$chatId,
		);
	}

	private function loadEvent(int $eventId): ?array
	{
		$events = \CCalendarEvent::GetList([
			'arFilter' => [
				'ID' => $eventId,
			],
			'fetchAttendees' => true,
			'checkPermissions' => false,
		]);

		return $events[0] ?? null;
	}

	private function isSupportedSharingEvent(?array $event): bool
	{
		if (!$event)
		{
			return false;
		}

		return in_array(
			$event['EVENT_TYPE'] ?? null,
			[
				Dictionary::EVENT_TYPE['shared'],
				Dictionary::EVENT_TYPE['shared_crm'],
				Dictionary::EVENT_TYPE['shared_collab'],
			],
			true,
		);
	}

	private function findChatId(array $event, int $organizerId): int
	{
		$chatId = (int)($event['MEETING']['CHAT_ID'] ?? 0);
		if ($chatId > 0 && Chat::getInstance($chatId)->isExist())
		{
			return $chatId;
		}

		$existingChatId = Secretary::getChatIdIfExists(
			(int)$event['ID'],
			\CCalendar::CALENDAR_CHAT_ENTITY_TYPE,
		);
		if ($existingChatId)
		{
			return (int)$existingChatId;
		}

		return $this->createChat($event, $organizerId);
	}

	private function createChat(array $event, int $organizerId): int
	{
		$attendeeIds = $this->extractAttendeeIds($event);
		if (empty($attendeeIds))
		{
			return 0;
		}

		return Secretary::createCalendarChat(
			[
				'ID' => (int)$event['ID'],
				'TITLE' => (string)($event['NAME'] ?? ''),
				'DATE_FROM' => $event['DATE_FROM'] ?? '',
				'DT_SKIP_TIME' => $event['DT_SKIP_TIME'] ?? 'N',
				'USER_IDS' => $attendeeIds,
				'MEETING' => is_array($event['MEETING'] ?? null) ? $event['MEETING'] : [],
				'CREATED_BY' => $organizerId,
			],
			$organizerId,
		);
	}

	/**
	 * @return int[]
	 */
	private function extractAttendeeIds(array $event): array
	{
		$attendeeIds = [];
		foreach ($event['ATTENDEE_LIST'] ?? [] as $attendee)
		{
			$status = $attendee['status'] ?? null;
			if (
				isset($attendee['id'])
				&& in_array($status, Dictionary::MEETING_STATUS, true)
				&& $status !== Dictionary::MEETING_STATUS['No']
			)
			{
				$attendeeIds[] = (int)$attendee['id'];
			}
		}

		return array_values(array_unique($attendeeIds));
	}

	/**
	 * @param Error[] $errors
	 */
	private function fail(EventLink $eventLink, string $step, string $errorCode, array $errors = []): JoinLinkResult
	{
		$this->logger->logFailure([
			'eventId' => $eventLink->getEventId(),
			'eventLinkId' => $eventLink->getId(),
			'ownerId' => $eventLink->getOwnerId(),
			'hostId' => $eventLink->getHostId(),
			'branch' => JoinLinkResult::BRANCH_GUEST_CHAT,
			'step' => $step,
			'errorCode' => $errorCode,
		]);

		if (empty($errors))
		{
			$errors = [new Error($errorCode)];
		}

		return JoinLinkResult::failure(JoinLinkResult::BRANCH_GUEST_CHAT, $errors);
	}

	/**
	 * @param Error[] $errors
	 */
	private function getFirstErrorCode(array $errors): string
	{
		$first = $errors[0] ?? null;

		return $first ? (string)$first->getCode() : 'unknown';
	}

	private function getShortClassName(\Throwable $e): string
	{
		$parts = explode('\\', get_class($e));

		return end($parts);
	}
}
