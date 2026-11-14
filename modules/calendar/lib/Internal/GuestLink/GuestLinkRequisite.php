<?php

declare(strict_types=1);

namespace Bitrix\Calendar\Internal\GuestLink;

use Bitrix\Calendar\Core\Event\Event as CalendarEvent;
use Bitrix\Calendar\Core\Event\Tools\Dictionary;
use Bitrix\Calendar\Internals\Log\Logger;
use Bitrix\Main\DI\ServiceLocator;

/**
 * Prepares the guest link requisite of a description channel (ics attachment, mail body).
 *
 * Every channel needs the same read-only step: look the resolver up, ask for the link the
 * event already has, keep the channel working when the resolve fails. This helper owns that
 * obvious wrapper together with its own log channel, so the resolver carries no caller-side
 * logging and a new channel does not add another copy.
 *
 * Read-only by contract: only {@see GuestLinkResolver::resolveExisting()} is used here.
 * A channel that renders a description must not create the meeting chat as a side effect -
 * the chat is created by an explicit user action or by the save flow.
 */
class GuestLinkRequisite
{
	protected const LOG_MARKER = 'DEBUG_CALENDAR_GUEST_LINK';

	/**
	 * @param string $channel channel name for the log line (mail, public-ics, card-ics)
	 */
	public function __construct(protected string $channel)
	{
	}

	/**
	 * Returns the guest link the event already has, or null when there is none.
	 *
	 * @param int $eventId event to resolve the link of; normalization of an attendee copy
	 *                     to the parent event, where the chat lives, is the resolver's job
	 * @param int $userId user to resolve on behalf of (the current user of the channel or,
	 *                    where there is none, the event organizer)
	 */
	public function resolveExistingUrl(int $eventId, int $userId): ?string
	{
		if ($eventId <= 0 || $userId <= 0)
		{
			return null;
		}

		try
		{
			/** @var GuestLinkResolver $resolver */
			$resolver = ServiceLocator::getInstance()->get(GuestLinkResolver::class);
			$result = $resolver->resolveExisting($eventId, $userId);
			if ($result->isSuccess())
			{
				return $result->getData()['url'] ?? null;
			}

			// NO_RIGHTS is a regular outcome, not a failure: the resolver checks the right on
			// the canonical parent event, while the channel resolved a user of its own (the
			// caller of an attendee copy, a stale host, an owner fallback). The channel simply
			// carries no requisite then.
			$failures = array_filter(
				$result->getErrors(),
				static fn($error) => $error->getCode() !== GuestLinkResolver::ERROR_NO_RIGHTS,
			);

			if ($failures)
			{
				$this->log(
					'resolveExisting failed for event ' . $eventId
					. ': ' . implode(', ', array_map(
						static fn($error) => $error->getCode() . ': ' . $error->getMessage(),
						$failures,
					))
				);
			}
		}
		catch (\Throwable $e)
		{
			$this->log($e);
		}

		return null;
	}

	/**
	 * Drops the legacy guest link line from the event description in memory.
	 *
	 * Applies to a call_sync event that has not gone through strip-on-save or the
	 * description scrubber yet: without this the channel would carry the link twice - once
	 * as the new requisite and once as the legacy line inside the description. Nothing is
	 * persisted, only the in-memory event is mutated.
	 */
	public static function stripLegacyLink(CalendarEvent $event): void
	{
		if ($event->getSpecialLabel() !== Dictionary::EVENT_TYPE['call_sync'])
		{
			return;
		}

		$description = (string)$event->getDescription();
		if ($description === '')
		{
			return;
		}

		$cleaned = LegacyDescriptionLink::cutFrom($description);
		if ($cleaned !== $description)
		{
			$event->setDescription($cleaned);
		}
	}

	protected function log(mixed $data): void
	{
		(new Logger(self::LOG_MARKER))->log(
			is_string($data) ? '[' . $this->channel . '] ' . $data : $data
		);
	}
}
