<?php

namespace Bitrix\Calendar\Internals\EventManager\EventSubscriber\Event;

use Bitrix\Calendar\Core\Queue\Queue\RevokeGuestLinkQueue;
use Bitrix\Calendar\Event\Event\AfterCalendarEventDeleted;
use Bitrix\Calendar\Internal\GuestLink\GuestLinkResolver;
use Bitrix\Calendar\Internals\EventManager\EventSubscriber\EventSubscriberInterface;
use Bitrix\Calendar\Internals\EventManager\EventSubscriber\EventSubscriberResponseTrait;
use Bitrix\Calendar\Internals\Log\Logger;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Event;
use Bitrix\Main\EventResult;

/**
 * Revokes the guest invite link of a deleted event.
 *
 * A calendar guest link has no expiration date of its own - a meeting may be scheduled far
 * ahead - so without this the guests of a cancelled meeting would keep entering its chat
 * forever. The chat itself stays alive: im can only delete it with the whole conversation.
 *
 * Deliberately does not use CalendarEventSubscriberTrait: the event is emitted after the row
 * is physically deleted, so there is nothing left to load. The agent finds chats for queued
 * events in one batch by their binding to the deleted event ids.
 */
final class RevokeGuestLinkAfterDelete implements EventSubscriberInterface
{
	use EventSubscriberResponseTrait;

	private const LOG_MARKER = 'DEBUG_CALENDAR_GUEST_LINK';

	public function __construct(
		private readonly ?RevokeGuestLinkQueue $queue = null,
		private readonly ?GuestLinkResolver $resolver = null,
	)
	{
	}

	public function __invoke(Event $event): EventResult
	{
		$eventId = (int)$event->getParameter('eventId');
		if ($eventId <= 0)
		{
			return $this->makeUndefinedResponse();
		}

		$seriesRootId = (int)$event->getParameter('seriesRootId');
		$creatorId = (int)$event->getParameter('creatorId');
		$ownerId = (int)$event->getParameter('ownerId');
		$chatId = (int)$event->getParameter('chatId');
		$resolver = $this->resolver;

		try
		{
			($this->queue ?? new RevokeGuestLinkQueue())->enqueue(
				$eventId,
				$chatId,
				$seriesRootId,
				$creatorId,
				$ownerId,
			);
		}
		catch (\Throwable $e)
		{
			self::log($e);
			$this->revokeSynchronously(
				$eventId,
				$chatId,
				$seriesRootId,
				$creatorId,
				$ownerId,
				$resolver ?? $this->getResolver(),
			);
		}

		return $this->makeSuccessResponse();
	}

	private function revokeSynchronously(
		int $eventId,
		int $chatId,
		int $seriesRootId,
		int $creatorId,
		int $ownerId,
		GuestLinkResolver $resolver,
	): void
	{
		try
		{
			$result = $resolver->revokeGuestLinks(
				[$eventId],
				null,
				$chatId > 0 ? [$eventId => $chatId] : [],
				$chatId > 0 ? [
					$eventId => [
						'seriesRootId' => $seriesRootId,
						'creatorId' => $creatorId,
						'ownerId' => $ownerId,
					],
				] : [],
			)[$eventId] ?? new \Bitrix\Main\Result();
			if (!$result->isSuccess())
			{
				self::log(implode(', ', $result->getErrorMessages()));
			}
		}
		catch (\Throwable $e)
		{
			self::log($e);
		}
	}

	private function getResolver(): GuestLinkResolver
	{
		return $this->resolver
			?? ServiceLocator::getInstance()->get(GuestLinkResolver::class);
	}

	public function getEventClasses(): array
	{
		return [
			AfterCalendarEventDeleted::class,
		];
	}

	private static function log(mixed $data): void
	{
		(new Logger(self::LOG_MARKER))->log($data);
	}
}
