<?php

declare(strict_types=1);

namespace Bitrix\Calendar\Internal\GuestLink;

use Bitrix\Calendar\Access\ActionDictionary;
use Bitrix\Calendar\Access\EventAccessController;
use Bitrix\Calendar\Core\Event\Event as CalendarEvent;
use Bitrix\Calendar\Core\Event\Tools\Dictionary;
use Bitrix\Calendar\Core\Mappers\Factory;
use Bitrix\Calendar\Core\Queue\Queue\RevokeGuestLinkQueue;
use Bitrix\Calendar\Internal\Integration\Im\GuestLinkService;
use Bitrix\Calendar\Internal\Integration\Intranet\GuestChatService;
use Bitrix\Calendar\Internals\EventTable;
use Bitrix\Calendar\Internals\Log\Logger;
use Bitrix\Main\Application;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Error;
use Bitrix\Main\ORM\Query\Filter\ConditionTree;
use Bitrix\Main\Result;

/**
 * Resolves the guest invite link of an eligible event.
 *
 * The link is backed by the meeting chat (`MEETING.CHAT_ID`) - the only persistent
 * link between the event and the chat; the invite url itself is never stored on the
 * event. The chat lives on the organizer's parent event, so attendee child copies
 * are normalized to `PARENT_ID` before any resolution ({@see getEvent()}). Read
 * access uses the immutable chat author as the im author so an event viewer who
 * is not a chat member still sees the existing link.
 *
 * Two flows:
 * - system flows ({@see ensureChat()}, {@see revokeGuestLink()}): no feature gate and no
 *   rights check - chat creation logic predates the guest-link feature and must not be
 *   gated by it, and the revoke acts on an event that is already deleted;
 * - user-facing flow ({@see getOrCreate()}, {@see resolveExisting()}): gated
 *   by {@see AvailabilityService} and event view rights.
 */
class GuestLinkResolver
{
	public const ERROR_FEATURE_OFF = 'FEATURE_OFF';
	public const ERROR_EVENT_NOT_SAVED = 'EVENT_NOT_SAVED';
	public const ERROR_NO_RIGHTS = 'NO_RIGHTS';
	public const ERROR_CHAT_CREATE_FAILED = 'CHAT_CREATE_FAILED';
	public const ERROR_LINK_RESOLVE_FAILED = 'LINK_RESOLVE_FAILED';

	/**
	 * Event types excluded from the guest link: system/pseudo events that carry
	 * their own sharing or relation mechanics and are not regular meetings.
	 * Emptying this list opens the feature to every meeting type - this array is
	 * the single switch to widen or narrow the scope.
	 */
	protected const EXCLUDED_EVENT_TYPES = [
		Dictionary::EVENT_TYPE['booking'],
		Dictionary::EVENT_TYPE['resource_booking'],
		Dictionary::EVENT_TYPE['shared'],
		Dictionary::EVENT_TYPE['shared_crm'],
		Dictionary::EVENT_TYPE['shared_collab'],
	];

	private const CHAT_CREATE_LOCK_PREFIX = 'calendar_guest_chat_';
	/** Seconds a competing request waits for the chat-creating holder. */
	public const CHAT_CREATE_LOCK_TIMEOUT = 5;

	protected AvailabilityService $availabilityService;
	protected GuestLinkService $imGuestLinkService;
	protected GuestChatService $intranetGuestChatService;
	protected RevokeGuestLinkQueue $revokeGuestLinkQueue;
	protected array $chatAuthorIds = [];
	protected array $attendeeIds = [];

	public function __construct(
		AvailabilityService $availabilityService,
		GuestLinkService $imGuestLinkService,
		GuestChatService $intranetGuestChatService,
		?RevokeGuestLinkQueue $revokeGuestLinkQueue = null,
	)
	{
		$this->availabilityService = $availabilityService;
		$this->imGuestLinkService = $imGuestLinkService;
		$this->intranetGuestChatService = $intranetGuestChatService;
		$this->revokeGuestLinkQueue = $revokeGuestLinkQueue ?? new RevokeGuestLinkQueue();
	}

	/**
	 * Returns the existing guest link without creating a chat or a new link.
	 * A null url is available only when the viewer is allowed to issue the link.
	 *
	 * Available to any user allowed to view the event (chat membership is not
	 * required); the link is resolved in im on behalf of the meeting host.
	 *
	 * @param int $eventId
	 * @param int $userId
	 * @return Result data: { available: bool, url: ?string }
	 */
	public function resolveExisting(int $eventId, int $userId): Result
	{
		$result = new Result();

		if (!$this->availabilityService->isAvailable($userId))
		{
			return $result->setData([
				'available' => false,
				'url' => null,
			]);
		}

		// The agreed scope extends guest-link access to employees only. The
		// preload paths (view/edit bootstraps) read the link without the
		// RestrictExternalUser prefilter that guards the user-facing actions, so
		// the external/collab guard lives here to cover every read caller.
		if ($this->isExternalUser($userId))
		{
			return $result->setData([
				'available' => false,
				'url' => null,
			]);
		}

		// The guest link applies to saved, non-excluded events (see isEligibleEvent).
		$event = $this->getEvent($eventId);
		if (!$this->isEligibleEvent($event, $eventId))
		{
			return $result->setData([
				'available' => false,
				'url' => null,
			]);
		}

		if (!$this->checkEventAction(ActionDictionary::ACTION_EVENT_VIEW_FULL, $userId, $event))
		{
			return $result->addError(new Error('Access denied', self::ERROR_NO_RIGHTS));
		}

		$chatId = $this->resolveExistingChatId($event, trustMeetingBlob: true);
		$authorId = $chatId > 0
			? $this->getChatAuthorId($chatId, $event)
			: $this->getTrustedChatAuthorId($event);
		if ($authorId <= 0 || !$this->imGuestLinkService->isChatWithGuestsAvailable($authorId))
		{
			return $result->setData([
				'available' => false,
				'url' => null,
			]);
		}

		if ($chatId <= 0)
		{
			return $result->setData([
				'available' => $this->canIssueLink($event, $userId),
				'url' => null,
			]);
		}

		$url = $this->imGuestLinkService->getActiveLinkUrl($chatId, $authorId);

		return $result->setData([
			'available' => $url !== null || $this->canIssueLink($event, $userId),
			'url' => $url,
		]);
	}

	/**
	 * Ensures the meeting chat exists, creating it when missing.
	 *
	 * System save-flow entrypoint: restores the pre-feature unconditional chat
	 * creation. No feature-availability gate and no rights check - the caller
	 * (post-save subscriber) acts on behalf of the organizer. The guest link
	 * is neither resolved nor created here.
	 *
	 * Callers that already resolved the canonical event may pass it in to avoid
	 * a second mapper load. The race-safe chat creation still re-reads the chat id
	 * from the DB under the lock ({@see createChatOnce()}), so reusing the
	 * pre-loaded event here does not weaken the re-check.
	 *
	 * @param int $eventId
	 * @param int $userId caller id kept for the existing system-flow contract
	 * @param CalendarEvent|null $event already resolved canonical event, if any
	 * @return Result data on success: { chatId: int }
	 */
	public function ensureChat(int $eventId, int $userId, ?CalendarEvent $event = null): Result
	{
		$result = new Result();

		$event ??= $this->getEvent($eventId);
		if (!$this->isEligibleEvent($event, $eventId))
		{
			return $result->addError(new Error('Event is not saved', self::ERROR_EVENT_NOT_SAVED));
		}

		$chatId = $this->resolveExistingChatId($event);
		if ($chatId <= 0)
		{
			$authorId = $this->getTrustedChatAuthorId($event);
			if ($authorId <= 0)
			{
				return $result->addError(new Error('Chat create failed', self::ERROR_CHAT_CREATE_FAILED));
			}

			$chatId = $this->createChatOnce($event, $authorId);
			if ($chatId <= 0)
			{
				return $result->addError(new Error('Chat create failed', self::ERROR_CHAT_CREATE_FAILED));
			}
		}

		$authorId = $this->getChatAuthorId($chatId, $event);
		if ($authorId <= 0)
		{
			return $result->addError(new Error('Chat create failed', self::ERROR_CHAT_CREATE_FAILED));
		}

		return $result->setData([
			'chatId' => $chatId,
			'authorId' => $authorId,
		]);
	}

	/**
	 * Returns the guest link, creating or reusing the meeting chat if needed.
	 *
	 * User-facing flow: checks feature availability, event validity and view
	 * rights. Issuing a link is limited to the trusted organizer or an event
	 * attendee, then the meeting chat is ensured via {@see ensureChat()}
	 * and resolves the link. The link is never persisted on the event.
	 *
	 * @param int $eventId
	 * @param int $userId
	 * @return Result data on success: { url: string }
	 */
	public function getOrCreate(int $eventId, int $userId): Result
	{
		$result = new Result();

		if (!$this->availabilityService->isAvailable($userId))
		{
			return $result->addError(new Error('Feature is off', self::ERROR_FEATURE_OFF));
		}

		// Same employee scope as resolveExisting(), checked before the event is loaded.
		// Not every create caller sits behind the RestrictExternalUser prefilter: the mail
		// invitation flow resolves on behalf of the organizer, who passes the view right as
		// the owner of their own section even when they are an extranet/collab user.
		if ($this->isExternalUser($userId))
		{
			return $result->addError(new Error('Access denied', self::ERROR_NO_RIGHTS));
		}

		$event = $this->getEvent($eventId);
		if (!$this->isEligibleEvent($event, $eventId))
		{
			return $result->addError(new Error('Event is not saved', self::ERROR_EVENT_NOT_SAVED));
		}

		if (!$this->checkEventAction(ActionDictionary::ACTION_EVENT_VIEW_FULL, $userId, $event))
		{
			return $result->addError(new Error('Access denied', self::ERROR_NO_RIGHTS));
		}

		if (!$this->canIssueLink($event, $userId))
		{
			return $result->addError(new Error('Access denied', self::ERROR_NO_RIGHTS));
		}

		$chatResult = $this->ensureChat($eventId, $userId, $event);
		if (!$chatResult->isSuccess())
		{
			return $result->addErrors($chatResult->getErrors());
		}

		$chatId = (int)($chatResult->getData()['chatId'] ?? 0);
		$authorId = (int)($chatResult->getData()['authorId'] ?? 0);
		if (!$this->imGuestLinkService->isChatWithGuestsAvailable($authorId))
		{
			return $result->addError(new Error('Feature is off', self::ERROR_FEATURE_OFF));
		}

		// The eligibility check above ran on a snapshot taken before the chat was created, and
		// creating it is network work. A delete that lands in that window has already run its
		// revoke, so minting now would hand out a link no one will ever revoke - the guest link
		// has no expiration of its own. Re-read the flag instead of trusting the snapshot.
		if ($this->isEventDeletedNow((int)$event->getId()))
		{
			return $result->addError(new Error('Event is not saved', self::ERROR_EVENT_NOT_SAVED));
		}

		$url = $this->imGuestLinkService->getOrCreateLinkUrl($chatId, $authorId);
		if ($url === null)
		{
			return $result->addError(new Error('Link resolve failed', self::ERROR_LINK_RESOLVE_FAILED));
		}

		if ($this->isEventDeletedNow((int)$event->getId()))
		{
			$result->addError(new Error('Event is not saved', self::ERROR_EVENT_NOT_SAVED));

			$revokeResult = $this->imGuestLinkService->revokeLink($chatId, $authorId);
			if (!$revokeResult->isSuccess())
			{
				$result->addErrors($revokeResult->getErrors());

				try
				{
					$this->revokeGuestLinkQueue->enqueue((int)$event->getId());
				}
				catch (\Throwable $e)
				{
					$result->addError(
						new Error($e->getMessage(), self::ERROR_LINK_RESOLVE_FAILED),
					);
				}
			}

			return $result;
		}

		return $result->setData(['url' => $url]);
	}

	public function getOrCreateForTrustedOrganizer(int $eventId): Result
	{
		$event = $this->getEvent($eventId);
		if (!$this->isEligibleEvent($event, $eventId))
		{
			return (new Result())->addError(
				new Error('Event is not saved', self::ERROR_EVENT_NOT_SAVED),
			);
		}

		$organizerId = $this->getTrustedChatAuthorId($event);
		if ($organizerId <= 0)
		{
			return (new Result())->addError(
				new Error('Chat create failed', self::ERROR_CHAT_CREATE_FAILED),
			);
		}

		return $this->getOrCreate($eventId, $organizerId);
	}

	/**
	 * Revokes the guest link issued for an event that no longer exists.
	 *
	 * System delete-flow entrypoint, symmetric to {@see ensureChat()}: no feature gate and no
	 * rights check - the caller is the post-delete subscriber, not a user. Nothing is loaded
	 * from the event: it is already deleted by the time this runs, and the chat is found by
	 * the entity binding it carries to the event id. That binding is also what keeps an
	 * attendee copy or a detached series instance - both of which carry the CHAT_ID of the
	 * event that owns the chat - from revoking a link that is still in use.
	 *
	 * Links in im are author-scoped. Calendar always issues this link for the immutable chat
	 * author, which can still be resolved after the event row is deleted.
	 *
	 * @return Result no errors when there is no chat or no link to revoke
	 */
	public function revokeGuestLink(int $eventId): Result
	{
		if ($eventId <= 0)
		{
			return new Result();
		}

		return $this->revokeGuestLinks([$eventId])[$eventId] ?? new Result();
	}

	public function hasLiveSeriesChatReference(
		int $deletedEventId,
		int $seriesRootId,
		int $creatorId,
		int $ownerId,
		int $chatId,
	): bool
	{
		return isset($this->getLiveSeriesChatIds(
			$deletedEventId,
			$seriesRootId,
			$creatorId,
			$ownerId,
		)[$chatId]);
	}

	/**
	 * @return array<int, true>
	 */
	protected function getLiveSeriesChatIds(
		int $deletedEventId,
		int $seriesRootId,
		int $creatorId,
		int $ownerId,
	): array
	{
		if (
			$deletedEventId <= 0
			|| $seriesRootId <= 0
			|| $creatorId <= 0
			|| $ownerId < 0
		)
		{
			return [];
		}

		$rows = EventTable::query()
			->setSelect(['MEETING'])
			->whereNot('ID', $deletedEventId)
			->where('DELETED', 'N')
			->where('CREATED_BY', $creatorId)
			->where('OWNER_ID', $ownerId)
			->whereColumn('PARENT_ID', 'ID')
			->where(
				(new ConditionTree())
					->logic(ConditionTree::LOGIC_OR)
					->where('ID', $seriesRootId)
					->where('RECURRENCE_ID', $seriesRootId),
			)
			->exec()
		;
		$chatIds = [];
		foreach ($rows as $row)
		{
			$meeting = is_string($row['MEETING'] ?? null)
				? unserialize($row['MEETING'], ['allowed_classes' => false])
				: null;
			$chatId = is_array($meeting) ? (int)($meeting['CHAT_ID'] ?? 0) : 0;
			if ($chatId > 0)
			{
				$chatIds[$chatId] = true;
			}
		}

		return $chatIds;
	}

	/**
	 * @param int[] $eventIds
	 * @param int|null $maxEventsWithChats Limits events, not chat rows. Every historical chat of a
	 * selected event is processed, so the number of revoke operations may exceed this limit. Events
	 * omitted by the limit remain unprocessed.
	 * @param array<int, int> $chatIdsByEventId event id => persisted chat id
	 * @param array<int, array{seriesRootId: int, creatorId: int, ownerId: int}> $seriesContextByEventId
	 * @return array<int, Result> Results for processed events; events omitted by the limit have no entry
	 */
	public function revokeGuestLinks(
		array $eventIds,
		?int $maxEventsWithChats = null,
		array $chatIdsByEventId = [],
		array $seriesContextByEventId = [],
	): array
	{
		$eventIds = array_values(array_unique(array_filter(
			array_map('intval', $eventIds),
			static fn(int $eventId): bool => $eventId > 0,
		)));
		if ($eventIds === [])
		{
			return [];
		}

		$chats = $this->imGuestLinkService->findCalendarChatsOfEvents($eventIds);
		if ($chats === null)
		{
			$results = [];
			foreach ($eventIds as $eventId)
			{
				$results[$eventId] = (new Result())->addError(
					new Error('IM is unavailable', self::ERROR_LINK_RESOLVE_FAILED),
				);
			}

			return $results;
		}

		$results = [];
		$processableEventIds = [];
		$snapshotChatIds = [];
		$selectedEventsWithChats = 0;
		foreach ($eventIds as $eventId)
		{
			$eventChats = $chats[$eventId] ?? [];
			$chatId = (int)($chatIdsByEventId[$eventId] ?? 0);
			$hasChatCandidate = $eventChats !== [] || $chatId > 0;
			if (
				$hasChatCandidate
				&& $maxEventsWithChats !== null
				&& $selectedEventsWithChats >= $maxEventsWithChats
			)
			{
				continue;
			}

			$processableEventIds[] = $eventId;
			if ($hasChatCandidate)
			{
				$selectedEventsWithChats++;
			}
			if ($chatId > 0)
			{
				$snapshotChatIds[] = $chatId;
			}
		}

		$snapshotBindings = $this->imGuestLinkService->getCalendarChatBindings($snapshotChatIds);
		if ($snapshotBindings === null)
		{
			foreach ($processableEventIds as $eventId)
			{
				$results[$eventId] = (new Result())->addError(
					new Error('IM is unavailable', self::ERROR_LINK_RESOLVE_FAILED),
				);
			}

			return $results;
		}

		foreach ($processableEventIds as $eventId)
		{
			$eventChats = $chats[$eventId] ?? [];
			$chatId = (int)($chatIdsByEventId[$eventId] ?? 0);
			$seriesContext = $seriesContextByEventId[$eventId] ?? [];
			$liveSeriesChatIds = $this->getLiveSeriesChatIds(
				$eventId,
				(int)($seriesContext['seriesRootId'] ?? 0),
				(int)($seriesContext['creatorId'] ?? 0),
				(int)($seriesContext['ownerId'] ?? 0),
			);
			$hasLiveReference = static fn(int $candidateChatId): bool => isset($liveSeriesChatIds[$candidateChatId]);
			$eventChats = array_values(array_filter(
				$eventChats,
				static fn(array $chat): bool => !$hasLiveReference((int)($chat['chatId'] ?? 0)),
			));
			if ($chatId > 0)
			{
				$binding = $snapshotBindings[$chatId] ?? null;
				if (
					$binding !== null
					&& $this->isTrustedRevokeChatBinding(
						$eventId,
						$binding['eventId'],
						$seriesContextByEventId[$eventId] ?? [],
					)
				)
				{
					if (!$hasLiveReference($chatId))
					{
						$eventChats[] = ['chatId' => $chatId, 'authorId' => $binding['authorId']];
					}
				}
			}
			$eventChats = $this->deduplicateChats($eventChats);

			$results[$eventId] = new Result();
			foreach ($eventChats as $chat)
			{
				$revokeResult = $this->imGuestLinkService->revokeLink($chat['chatId'], $chat['authorId']);
				if (!$revokeResult->isSuccess())
				{
					$results[$eventId]->addErrors($revokeResult->getErrors());
				}
			}
		}

		return $results;
	}

	private function deduplicateChats(array $chats): array
	{
		$uniqueChats = [];
		foreach ($chats as $chat)
		{
			$chatId = (int)($chat['chatId'] ?? 0);
			$authorId = (int)($chat['authorId'] ?? 0);
			if ($chatId > 0 && $authorId > 0)
			{
				$uniqueChats[$chatId] = [
					'chatId' => $chatId,
					'authorId' => $authorId,
				];
			}
		}

		return array_values($uniqueChats);
	}

	protected function isTrustedRevokeChatBinding(
		int $deletedEventId,
		int $chatEventId,
		array $seriesContext,
	): bool
	{
		if ($deletedEventId <= 0 || $chatEventId <= 0)
		{
			return false;
		}

		if ($chatEventId === $deletedEventId)
		{
			return true;
		}

		$seriesRootId = (int)($seriesContext['seriesRootId'] ?? 0);
		$creatorId = (int)($seriesContext['creatorId'] ?? 0);
		$ownerId = (int)($seriesContext['ownerId'] ?? 0);
		if ($seriesRootId <= 0 || $creatorId <= 0 || $ownerId < 0)
		{
			return false;
		}

		$chatEvent = $this->getSeriesChatOwnerRow($chatEventId);
		if ($chatEvent === null)
		{
			return false;
		}

		$chatEventRecurrenceId = (int)$chatEvent['RECURRENCE_ID'];
		$chatEventRootId = $chatEventRecurrenceId > 0
			? $chatEventRecurrenceId
			: (int)$chatEvent['ID'];

		return $chatEventRootId === $seriesRootId
			&& (int)$chatEvent['PARENT_ID'] === (int)$chatEvent['ID']
			&& (int)$chatEvent['CREATED_BY'] === $creatorId
			&& (int)$chatEvent['OWNER_ID'] === $ownerId;
	}

	protected function getEvent(int $eventId): ?CalendarEvent
	{
		if ($eventId <= 0)
		{
			return null;
		}

		/** @var Factory $mapperFactory */
		$mapperFactory = ServiceLocator::getInstance()->get('calendar.service.mappers.factory');

		$event = $mapperFactory->getEvent()->getById($eventId);

		// The meeting chat (CHAT_ID) lives on the organizer's parent event and may
		// not be replicated to an attendee child copy yet: normalize to the parent
		// so rights and the chat are always resolved on the same canonical event
		// (mirrors the PARENT_ID resolution of the email invitation flow).
		$parentId = (int)($event?->getParentId() ?? 0);
		if ($event !== null && $parentId > 0 && $parentId !== (int)$event->getId())
		{
			$parentEvent = $mapperFactory->getEvent()->getById($parentId);
			if ($parentEvent !== null)
			{
				return $parentEvent;
			}
		}

		return $event;
	}

	protected function isEligibleEvent(?CalendarEvent $event, int $eventId): bool
	{
		// Any saved event qualifies for the guest link except the excluded system/pseudo
		// types (see EXCLUDED_EVENT_TYPES). No meeting/chat precondition here: the chat is
		// reused or created lazily, and a plain event stores its chat id the same way.
		// Deletion is soft, so the mapper still returns a deleted event: without the check
		// a delayed notification would mint a chat and a link for an event that is gone.
		return $event !== null
			&& $eventId > 0
			&& !$event->isDeleted()
			&& !in_array($event->getSpecialLabel(), self::EXCLUDED_EVENT_TYPES, true);
	}

	protected function getTrustedChatAuthorId(CalendarEvent $event): int
	{
		if ($event->getCalendarType() === Dictionary::CALENDAR_TYPE['user'])
		{
			$sectionOwnerId = (int)($event->getSection()->getOwner()?->getId() ?? 0);
			if ($sectionOwnerId > 0)
			{
				return $sectionOwnerId;
			}
		}

		return (int)($event->getCreator()?->getId() ?? 0);
	}

	protected function getChatAuthorId(int $chatId, CalendarEvent $event): int
	{
		$eventId = (int)$event->getId();
		$key = $eventId . ':' . $chatId;
		if (!array_key_exists($key, $this->chatAuthorIds))
		{
			$authorId = $this->imGuestLinkService->getCalendarChatAuthorId($chatId, $eventId);
			if ($authorId <= 0)
			{
				$binding = $this->imGuestLinkService->getCalendarChatBinding($chatId);
				if (
					$binding !== null
					&& $this->isTrustedSeriesChatOwner($event, $binding['eventId'], $chatId)
				)
				{
					$authorId = $binding['authorId'];
				}
			}

			$this->chatAuthorIds[$key] = $authorId;
		}

		return $this->chatAuthorIds[$key];
	}

	protected function isTrustedSeriesChatOwner(CalendarEvent $event, int $chatEventId, int $chatId): bool
	{
		$eventId = (int)$event->getId();
		$recurrenceId = (int)($event->getRecurrenceId() ?? 0);
		$seriesRootId = $recurrenceId > 0
			? $recurrenceId
			: ($event->getRecurringRule() !== null ? $eventId : 0);
		if ($seriesRootId <= 0 || $chatEventId <= 0 || $chatEventId === $eventId)
		{
			return false;
		}

		$eventCreatorId = (int)($event->getCreator()?->getId() ?? 0);
		$eventParentId = (int)($event->getParentId() ?? 0);
		if ($eventCreatorId <= 0 || $eventParentId !== $eventId)
		{
			return false;
		}

		$chatEvent = $this->getSeriesChatOwnerRow($chatEventId);
		if ($chatEvent === null)
		{
			$this->logChatBindingMismatch($eventId, $chatEventId);

			return false;
		}

		$chatEventRecurrenceId = (int)($chatEvent['RECURRENCE_ID'] ?? 0);
		$chatEventRootId = $chatEventRecurrenceId > 0
			? $chatEventRecurrenceId
			: (int)($chatEvent['ID'] ?? 0);

		$isTrusted = $chatEventRootId === $seriesRootId
			&& (int)($chatEvent['PARENT_ID'] ?? 0) === (int)($chatEvent['ID'] ?? 0)
			&& (int)($chatEvent['CREATED_BY'] ?? 0) === $eventCreatorId
			&& (
				(int)($event->getOwner()?->getId() ?? 0) <= 0
				|| (int)($chatEvent['OWNER_ID'] ?? 0) === (int)$event->getOwner()?->getId()
			);
		if (
			$isTrusted
			&& ($chatEvent['DELETED'] ?? 'Y') === 'Y'
			&& !$this->hasLiveSeriesChatReference(
				$chatEventId,
				$seriesRootId,
				$eventCreatorId,
				(int)($chatEvent['OWNER_ID'] ?? 0),
				$chatId,
			)
		)
		{
			$isTrusted = false;
		}
		if (!$isTrusted)
		{
			$this->logChatBindingMismatch($eventId, $chatEventId);
		}

		return $isTrusted;
	}

	/**
	 * @return array{ID: int, PARENT_ID: int, RECURRENCE_ID: int, DELETED: string, CREATED_BY: int, OWNER_ID: int}|null
	 */
	protected function getSeriesChatOwnerRow(int $eventId): ?array
	{
		$row = EventTable::query()
			->setSelect(['ID', 'PARENT_ID', 'RECURRENCE_ID', 'DELETED', 'CREATED_BY', 'OWNER_ID'])
			->where('ID', $eventId)
			->exec()
			->fetch()
		;

		return is_array($row) ? $row : null;
	}

	protected function logChatBindingMismatch(int $eventId, int $chatEventId): void
	{
		(new Logger('DEBUG_CALENDAR_GUEST_LINK'))->log([
			'message' => 'Calendar chat binding does not match the event series',
			'eventId' => $eventId,
			'chatEventId' => $chatEventId,
		]);
	}

	/**
	 * Resolves the chat id of the event from the loaded MEETING blob, falling back to a
	 * raw read of the same field.
	 *
	 * $trustMeetingBlob is the read path: a meeting keeps its chat id in MEETING, so no
	 * CHAT_ID there means no chat yet and the extra select is pointless. The write path
	 * cannot trust the blob: the mapper cache is static for the whole hit and
	 * Intranet\Secretary writes CHAT_ID past the mapper, so a chat created a moment ago
	 * is still missing from a hydrated event.
	 *
	 * A plain (non-meeting) event is raw-read on either path: getMeetingDescription()
	 * requires HOST_NAME and returns null for it even though CHAT_ID is physically stored
	 * in MEETING - and the feature creates exactly such events.
	 */
	protected function resolveExistingChatId(CalendarEvent $event, bool $trustMeetingBlob = false): int
	{
		$meetingFields = $event->getMeetingDescription()?->getFields();
		$storedChatId = (int)($meetingFields['CHAT_ID'] ?? 0);
		if ($storedChatId > 0 && $this->isChatOfEvent($storedChatId, $event))
		{
			return $storedChatId;
		}

		$seriesChatId = $this->fetchSeriesMasterChatId($event);
		if ($seriesChatId > 0)
		{
			return $seriesChatId;
		}

		if ($trustMeetingBlob && $meetingFields !== null)
		{
			return 0;
		}

		return $this->fetchChatIdOfEvent($event);
	}

	protected function fetchSeriesMasterChatId(CalendarEvent $event): int
	{
		$seriesRootId = (int)($event->getRecurrenceId() ?? 0);
		if ($seriesRootId <= 0)
		{
			return 0;
		}

		$chatId = $this->fetchActualChatId($seriesRootId);

		return $this->isChatOfEvent($chatId, $event) ? $chatId : 0;
	}

	/**
	 * Reads the persisted chat id and keeps it only if the chat belongs to this event.
	 */
	protected function fetchChatIdOfEvent(CalendarEvent $event): int
	{
		$eventId = (int)$event->getId();
		$chatId = $this->fetchActualChatId($eventId);

		return $this->isChatOfEvent($chatId, $event) ? $chatId : 0;
	}

	/**
	 * Creates the meeting chat under a named DB lock so a parallel request on the
	 * same event reuses the chat instead of creating a second one. The chat id is
	 * re-read from the DB under the lock: the request-level mapper cache would
	 * hide a CHAT_ID written by the competing process.
	 */
	protected function createChatOnce(CalendarEvent $event, int $authorId): int
	{
		$lockName = self::getChatCreateLockName(
			(int)$event->getId(),
			(int)($event->getRecurrenceId() ?? 0),
		);

		if (!$this->acquireChatCreateLock($lockName))
		{
			// Contended past the timeout: read the id the holder wrote instead of waiting a
			// second full timeout. One wait is the whole budget a request may spend here -
			// the email agent has a batch of notifications to get through, and a premature
			// 0 read only costs this caller the requisite, not the chat.
			$chatId = $this->fetchChatIdOfEvent($event);

			return $chatId > 0 ? $chatId : $this->fetchSeriesMasterChatId($event);
		}

		try
		{
			$eventId = (int)$event->getId();
			$chatId = $this->fetchChatIdOfEvent($event);
			if ($chatId > 0)
			{
				return $chatId;
			}

			$chatId = $this->fetchSeriesMasterChatId($event);
			if ($chatId > 0)
			{
				return $chatId;
			}

			if ($this->isEventDeletedNow($eventId))
			{
				return 0;
			}

			return $this->createChat($event, $authorId);
		}
		finally
		{
			$this->releaseChatCreateLock($lockName);
		}
	}

	public static function getChatCreateLockName(int $eventId, int $seriesRootId = 0): string
	{
		return self::CHAT_CREATE_LOCK_PREFIX . ($seriesRootId > 0 ? $seriesRootId : $eventId);
	}

	protected function acquireChatCreateLock(string $lockName): bool
	{
		return Application::getConnection()->lock($lockName, self::CHAT_CREATE_LOCK_TIMEOUT);
	}

	protected function releaseChatCreateLock(string $lockName): void
	{
		Application::getConnection()->unlock($lockName);
	}

	/**
	 * Whether the event is gone right now, read past the request-level mapper cache.
	 *
	 * Deletion is soft in the usual flow, but a hard delete drops the row - a missing row counts
	 * as deleted just the same.
	 */
	protected function isEventDeletedNow(int $eventId): bool
	{
		$row = EventTable::query()
			->setSelect(['DELETED'])
			->where('ID', $eventId)
			->exec()
			->fetch()
		;

		return !is_array($row) || $row['DELETED'] === 'Y';
	}

	/**
	 * Reads CHAT_ID directly from the DB, bypassing the request-level mapper cache.
	 *
	 * A raw read only: whether the chat may be used at all is decided by the caller
	 * ({@see fetchChatIdOfEvent()}).
	 */
	protected function fetchActualChatId(int $eventId): int
	{
		$row = EventTable::query()
			->setSelect(['MEETING'])
			->where('ID', $eventId)
			->exec()
			->fetch()
		;

		$meeting = is_string($row['MEETING'] ?? null)
			? unserialize($row['MEETING'], ['allowed_classes' => false])
			: null;

		return is_array($meeting) ? (int)($meeting['CHAT_ID'] ?? 0) : 0;
	}

	/**
	 * Whether the chat is the calendar chat of this very event.
	 *
	 * MEETING.CHAT_ID alone is not a trusted link: the event write path takes chat_id
	 * straight from the request (CalendarEntryAjax::getCommandFields()), so the stored id
	 * may point at a chat that has nothing to do with the event. Minting a guest invite
	 * link into such a chat would hand its conversation to an outsider, so existence is
	 * not enough - the chat must be bound to this event by its entity fields, the way
	 * Intranet\Secretary::createCalendarChat() writes them.
	 */
	protected function isChatOfEvent(int $chatId, CalendarEvent $event): bool
	{
		return $this->getChatAuthorId($chatId, $event) > 0;
	}

	protected function createChat(CalendarEvent $event, int $authorId): int
	{
		$eventFields = $this->getCalendarChatFields($event, $authorId);
		$chatId = $this->intranetGuestChatService->createCalendarChat($eventFields, $authorId);
		$seriesRootId = (int)($event->getRecurrenceId() ?? 0);
		if ($chatId > 0 && $seriesRootId > 0)
		{
			$this->persistSeriesMasterChatId($event, $chatId);
		}

		return $chatId;
	}

	/**
	 * Persists a recurrence chat on the series master while createChatOnce() holds the root lock.
	 */
	protected function persistSeriesMasterChatId(CalendarEvent $event, int $chatId): void
	{
		$seriesRootId = (int)($event->getRecurrenceId() ?? 0);
		if ($seriesRootId <= 0 || $chatId <= 0)
		{
			return;
		}

		$master = $this->fetchPersistedSeriesMaster($seriesRootId);
		$meeting = $master['MEETING'] ?? null;
		if (
			!is_array($meeting)
			|| (int)($meeting['CHAT_ID'] ?? 0) > 0
			|| (int)($master['ID'] ?? 0) !== $seriesRootId
			|| (int)($master['PARENT_ID'] ?? 0) !== $seriesRootId
			|| (int)($master['RECURRENCE_ID'] ?? 0) > 0
			|| (int)($master['CREATED_BY'] ?? 0) !== (int)($event->getCreator()?->getId() ?? 0)
			|| (int)($master['OWNER_ID'] ?? 0) !== (int)($event->getOwner()?->getId() ?? 0)
			|| in_array((string)($master['EVENT_TYPE'] ?? ''), self::EXCLUDED_EVENT_TYPES, true)
		)
		{
			return;
		}

		$meeting['CHAT_ID'] = $chatId;
		if (!$this->updatePersistedMeetingFields($seriesRootId, $meeting))
		{
			$this->logSeriesMasterChatPersistenceFailure($seriesRootId, $chatId, 'update_failed');
		}
	}

	protected function fetchPersistedSeriesMaster(int $eventId): ?array
	{
		$row = EventTable::query()
			->setSelect([
				'ID',
				'PARENT_ID',
				'RECURRENCE_ID',
				'DELETED',
				'CREATED_BY',
				'OWNER_ID',
				'EVENT_TYPE',
				'MEETING',
			])
			->where('ID', $eventId)
			->exec()
			->fetch()
		;
		if (!is_array($row) || $row['DELETED'] === 'Y')
		{
			return null;
		}

		$meeting = is_string($row['MEETING'] ?? null)
			? unserialize($row['MEETING'], ['allowed_classes' => false])
			: null;

		$row['MEETING'] = is_array($meeting) ? $meeting : [];

		return $row;
	}

	protected function updatePersistedMeetingFields(int $eventId, array $meeting): bool
	{
		$result = EventTable::update($eventId, ['MEETING' => serialize($meeting)]);
		if (!$result->isSuccess())
		{
			return false;
		}

		\CCalendar::ClearCache('event_list');

		return true;
	}

	protected function logSeriesMasterChatPersistenceFailure(int $eventId, int $chatId, string $reason): void
	{
		(new Logger('DEBUG_CALENDAR_GUEST_LINK'))->log([
			'message' => 'Failed to persist the series master chat pointer',
			'eventId' => $eventId,
			'chatId' => $chatId,
			'reason' => $reason,
		]);
	}

	private function getCalendarChatFields(CalendarEvent $event, int $authorId): array
	{
		return [
			'ID' => $event->getId(),
			'TITLE' => $event->getName() ?? '',
			'DATE_FROM' => (string)$event->getStart(),
			'DT_SKIP_TIME' => $event->isFullDayEvent() ? 'Y' : 'N',
			'USER_IDS' => $this->getAttendeeIds($event, $authorId),
			'MEETING' => $event->getMeetingDescription()?->getFields() ?? [],
			'CREATED_BY' => $authorId,
		];
	}

	protected function getAttendeeIds(CalendarEvent $event, int $organizerId): array
	{
		$cacheKey = $event->getId() . ':' . $organizerId;
		if (isset($this->attendeeIds[$cacheKey]))
		{
			return $this->attendeeIds[$cacheKey];
		}

		$attendeeIds = [];
		foreach ($this->getAttendeeList($event->getId()) as $attendee)
		{
			$attendeeId = (int)($attendee['id'] ?? 0);
			if (
				$attendeeId > 0
				&& ($attendee['status'] ?? null) !== Dictionary::MEETING_STATUS['No']
			)
			{
				$attendeeIds[] = $attendeeId;
			}
		}
		$attendeeIds = array_values(array_unique($attendeeIds));

		// A plain personal event may have no attendees at all; the chat still needs its
		// organizer as a member (mirrors controlbutton's USER_IDS -> [CREATED_BY]
		// fallback), otherwise Secretary::createCalendarChat builds a chat with no users.
		if (!in_array($organizerId, $attendeeIds, true))
		{
			$attendeeIds[] = $organizerId;
		}

		return $this->attendeeIds[$cacheKey] = $attendeeIds;
	}

	protected function getAttendeeList(int $eventId): array
	{
		$result = \CCalendarEvent::getAttendeeList([$eventId], [$eventId]);

		return $result['attendeeList'][$eventId] ?? [];
	}

	protected function canIssueLink(CalendarEvent $event, int $userId): bool
	{
		$organizerId = $this->getTrustedChatAuthorId($event);

		return $userId === $organizerId
			|| in_array($userId, $this->getAttendeeIds($event, $organizerId), true);
	}

	/**
	 * Whether the user is an extranet or collab user. Such users are outside the
	 * employee scope the guest link was opened up to, so the read flow hides the
	 * link from them regardless of their view rights on the event. Delegates to
	 * {@see AvailabilityService} so the external-user rule has a single owner.
	 */
	protected function isExternalUser(int $userId): bool
	{
		return $this->availabilityService->isExternalUser($userId);
	}

	protected function checkEventAction(string $action, int $userId, CalendarEvent $event): bool
	{
		$accessController = new EventAccessController($userId);
		$eventModel = \CCalendarEvent::getEventModelForPermissionCheck((int)$event->getId(), [], $userId);

		return $accessController->check($action, $eventModel);
	}
}
