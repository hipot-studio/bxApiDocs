<?php

declare(strict_types=1);

namespace Bitrix\Calendar\Infrastructure\Agent\GuestLink;

use Bitrix\Calendar\Core\Event\Tools\Dictionary;
use Bitrix\Calendar\Core\Mappers\Factory;
use Bitrix\Calendar\Internal\GuestLink\GuestLinkResolver;
use Bitrix\Calendar\Internal\GuestLink\LegacyDescriptionLink;
use Bitrix\Calendar\Internals\EventTable;
use Bitrix\Main\Application;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\ORM\Fields\ExpressionField;
use Bitrix\Main\SystemException;
use Bitrix\Main\Update\Stepper;
use Throwable;

/**
 * One-shot cleanup of the legacy marked guest link inside DESCRIPTION of
 * '#call_sync#' events.
 *
 * Under the current model the guest link is never written into DESCRIPTION:
 * it is resolved on demand and the strip-on-save subscriber removes any legacy
 * marked link whenever a call_sync event is re-saved. Events created before
 * that change still carry the marked link until they happen to be re-saved.
 * This scrubber proactively strips it instead of waiting for an organic
 * re-save.
 *
 * Past events are cleaned as well. The stored line is not stale text: it is a
 * working invite into the meeting chat, which outlives the event it was created
 * for, and it is readable by everyone who may read the description - including the
 * extranet/collab users that {@see \Bitrix\Calendar\Internal\GuestLink\GuestLinkResolver}
 * hides the link from in every channel that resolves it. Leaving the line in
 * the past rows would keep that one channel around forever.
 *
 * EVENT_TYPE and DESCRIPTION are not indexed, so we cannot ask the DB to hand
 * us the rare matching rows directly without a full scan per step. Instead we
 * walk the table in fixed PRIMARY-key windows and, within each window, select
 * only the candidate rows (call_sync type, marker present). Each step scans a
 * bounded range of consecutive IDs and runs at most {@see SAVE_LIMIT} silent
 * saves regardless of how many rows match, so step cost stays predictable. The
 * upper bound is a MAX(ID) snapshot taken once: events created after the run
 * starts already follow the new model and never carry the marker.
 *
 * Stripping reuses the silent save-flow (no invitations, no edit
 * notifications) so the search index stays consistent and meeting child copies
 * (PARENT_ID) are handled by the canonical write-path. The operation is
 * idempotent: rows without the marker are skipped and a second run is a no-op.
 */
class DescriptionGuestLinkScrubber extends Stepper
{
	/** Width of the PRIMARY-key window scanned per step. */
	private const ID_WINDOW = 3000;

	/** Upper bound on the number of actual silent saves performed per step. */
	private const SAVE_LIMIT = 50;

	private const FAILED_RETRY_LIMIT = 10;
	private const MAX_EVENT_ATTEMPTS = 3;
	private const MAX_WINDOW_ATTEMPTS = 3;

	protected static $moduleId = 'calendar';

	private array $option;

	public function execute(array &$option): bool
	{
		$this->option = &$option;

		$maxId = $this->getMaxId();
		$this->retryFailedEvents();

		if ($maxId <= 0)
		{
			return $this->hasFailedEvents() ? self::CONTINUE_EXECUTION : self::FINISH_EXECUTION;
		}

		$lastId = $this->getLastId();

		if ($lastId >= $maxId)
		{
			return $this->hasFailedEvents() ? self::CONTINUE_EXECUTION : self::FINISH_EXECUTION;
		}

		$upperId = min($lastId + self::ID_WINDOW, $maxId);

		// Default: the whole window is consumed this step. When the per-step save
		// cap is hit, the cursor is rewound to just before the first deferred id so
		// the rest of the window is picked up on the next step.
		$resumeId = $upperId;

		try
		{
			$candidateIds = $this->fetchCandidateIds($lastId, $upperId);
		}
		catch (Throwable $e)
		{
			$this->logWindowFailure($lastId, $upperId, $e);
			$attempts = (int)($this->option['failedWindowAttempts'] ?? 0) + 1;
			if ($attempts >= self::MAX_WINDOW_ATTEMPTS)
			{
				$this->logWindowRetryLimitReached($lastId, $upperId, $e);
				$this->setLastId($upperId);
				unset($this->option['failedWindowAttempts']);
			}
			else
			{
				$this->option['failedWindowAttempts'] = $attempts;
			}

			return self::CONTINUE_EXECUTION;
		}
		unset($this->option['failedWindowAttempts']);

		$processed = 0;
		foreach ($candidateIds as $eventId)
		{
			if ($processed >= self::SAVE_LIMIT)
			{
				$resumeId = $eventId - 1;
				break;
			}

			try
			{
				$this->stripGuestLinkFromDescription($eventId);
			}
			catch (Throwable $e)
			{
				$this->logEventFailure($eventId, $e);
				$this->option['failedEventIds'][$eventId] = 1;
			}

			$processed++;
		}

		$this->setLastId($resumeId);

		return self::CONTINUE_EXECUTION;
	}

	private function retryFailedEvents(): void
	{
		$processed = 0;
		foreach ($this->option['failedEventIds'] ?? [] as $eventId => $attempts)
		{
			if ($processed >= self::FAILED_RETRY_LIMIT)
			{
				break;
			}

			unset($this->option['failedEventIds'][$eventId]);
			try
			{
				$this->stripGuestLinkFromDescription((int)$eventId);
			}
			catch (Throwable $e)
			{
				$this->logEventFailure((int)$eventId, $e);
				$attempts = (int)$attempts + 1;
				if ($attempts < self::MAX_EVENT_ATTEMPTS)
				{
					$this->option['failedEventIds'][$eventId] = $attempts;
				}
				else
				{
					$this->logRetryLimitReached((int)$eventId, $e);
				}
			}
			$processed++;
		}

		if (($this->option['failedEventIds'] ?? []) === [])
		{
			unset($this->option['failedEventIds']);
		}
	}

	private function hasFailedEvents(): bool
	{
		return ($this->option['failedEventIds'] ?? []) !== [];
	}

	protected function logEventFailure(int $eventId, Throwable $e): void
	{
		Application::getInstance()
			->getExceptionHandler()
			->writeToLog(
				new SystemException(
					sprintf(
						'DescriptionGuestLinkScrubber: event %d failed: %s',
						$eventId,
						$e->getMessage(),
					),
					$e->getCode(),
					previous: $e,
				),
			)
		;
	}

	protected function logWindowFailure(int $lowerId, int $upperId, Throwable $e): void
	{
		Application::getInstance()
			->getExceptionHandler()
			->writeToLog(
				new SystemException(
					sprintf(
						'DescriptionGuestLinkScrubber: window (%d, %d] failed: %s',
						$lowerId,
						$upperId,
						$e->getMessage(),
					),
					$e->getCode(),
					previous: $e,
				),
			)
		;
	}

	protected function logRetryLimitReached(int $eventId, Throwable $e): void
	{
		Application::getInstance()
			->getExceptionHandler()
			->writeToLog(
				new SystemException(
					sprintf(
						'DescriptionGuestLinkScrubber: event %d skipped after %d attempts: %s',
						$eventId,
						self::MAX_EVENT_ATTEMPTS,
						$e->getMessage(),
					),
					$e->getCode(),
					previous: $e,
				),
			)
		;
	}

	protected function logWindowRetryLimitReached(int $lowerId, int $upperId, Throwable $e): void
	{
		Application::getInstance()
			->getExceptionHandler()
			->writeToLog(
				new SystemException(
					sprintf(
						'DescriptionGuestLinkScrubber: window (%d, %d] skipped after %d attempts: %s',
						$lowerId,
						$upperId,
						self::MAX_WINDOW_ATTEMPTS,
						$e->getMessage(),
					),
					$e->getCode(),
					previous: $e,
				),
			)
		;
	}

	/**
	 * IDs of call_sync events in the (lowerId, upperId] window whose DESCRIPTION
	 * still contains the marked guest link.
	 *
	 * Ordered by ID ascending and capped at SAVE_LIMIT + 1: execute() processes
	 * up to SAVE_LIMIT of them and uses the extra row to know whether the window
	 * holds more work to resume on the next step.
	 *
	 * @return int[]
	 */
	protected function fetchCandidateIds(int $lowerId, int $upperId): array
	{
		// Soft-deleted rows (DELETED='Y') are excluded: stripping a guest link
		// runs through the silent save-flow, and re-saving a soft-deleted event
		// would resurrect it as a side effect of the one-shot migration.
		$rows = EventTable::query()
			->setSelect(['ID'])
			->where('EVENT_TYPE', Dictionary::EVENT_TYPE['call_sync'])
			->where('DELETED', 'N')
			->where('ID', '>', $lowerId)
			->where('ID', '<=', $upperId)
			->whereLike('DESCRIPTION', '%' . LegacyDescriptionLink::MARKER . '%')
			->setOrder(['ID' => 'ASC'])
			->setLimit(self::SAVE_LIMIT + 1)
			->fetchAll()
		;

		return array_map(static fn(array $row): int => (int)$row['ID'], $rows);
	}

	/**
	 * Mirrors the strip-on-save behaviour: load the event, cut the marked link
	 * and persist it through the silent save-flow without notifications.
	 */
	protected function stripGuestLinkFromDescription(int $eventId): void
	{
		$lockEventId = $this->getEventLockId($eventId);
		if (!$this->acquireEventLock($lockEventId))
		{
			throw new SystemException("Failed to acquire event lock {$lockEventId}");
		}

		try
		{
			$this->stripGuestLinkFromActualEvent($eventId);
		}
		finally
		{
			$this->releaseEventLock($lockEventId);
		}
	}

	protected function getEventLockId(int $eventId): int
	{
		$row = EventTable::query()
			->setSelect(['RECURRENCE_ID'])
			->where('ID', $eventId)
			->exec()
			->fetch()
		;
		$seriesRootId = (int)($row['RECURRENCE_ID'] ?? 0);

		return $seriesRootId > 0 ? $seriesRootId : $eventId;
	}

	private function stripGuestLinkFromActualEvent(int $eventId): void
	{
		/** @var Factory $mapperFactory */
		$mapperFactory = ServiceLocator::getInstance()->get('calendar.service.mappers.factory');

		$eventMapper = $mapperFactory->getEvent();
		$eventMapper->resetCacheById($eventId);
		$calendarEvent = $eventMapper->getById($eventId);
		if (!$calendarEvent || $calendarEvent->isDeleted())
		{
			return;
		}

		$currentDescription = (string)$calendarEvent->getDescription();
		$cleanedDescription = LegacyDescriptionLink::cutFrom($currentDescription);

		if ($cleanedDescription === $currentDescription)
		{
			return;
		}

		$organizerId = $calendarEvent->getEventHost()?->getId() ?? $calendarEvent->getCreator()?->getId();
		if ($organizerId === null)
		{
			// An event without both host and creator is anomalous data;
			// skip it rather than persist an update with an undefined actor.
			return;
		}

		$calendarEvent->setDescription($cleanedDescription);

		$updatedEvent = $eventMapper->update($calendarEvent, [
			'userId' => $organizerId,
			'overSaving' => true,
			'sendInvitations' => false,
			'sendEditNotification' => false,
		]);
		if ($updatedEvent === null)
		{
			throw new SystemException("Failed to update calendar event {$eventId}");
		}
	}

	protected function acquireEventLock(int $eventId): bool
	{
		return Application::getConnection()->lock(
			GuestLinkResolver::getChatCreateLockName($eventId),
			GuestLinkResolver::CHAT_CREATE_LOCK_TIMEOUT,
		);
	}

	protected function releaseEventLock(int $eventId): void
	{
		Application::getConnection()->unlock(GuestLinkResolver::getChatCreateLockName($eventId));
	}

	/**
	 * Largest event ID at the moment the scrubber starts. Snapshotted once and
	 * persisted in the option: rows created later already follow the new model.
	 */
	protected function getMaxId(): int
	{
		if (isset($this->option['maxId']))
		{
			return (int)$this->option['maxId'];
		}

		$row = EventTable::query()
			->addSelect(new ExpressionField('MAX_ID', 'MAX(%s)', 'ID'))
			->exec()
			->fetch()
		;

		$maxId = (int)($row['MAX_ID'] ?? 0);
		$this->option['maxId'] = $maxId;

		return $maxId;
	}

	private function getLastId(): int
	{
		return (int)($this->option['lastId'] ?? 0);
	}

	private function setLastId(int $id): void
	{
		$this->option['lastId'] = $id;
	}
}
