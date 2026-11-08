<?php

declare(strict_types=1);

namespace Bitrix\Calendar\Synchronization\Internal\Service\Export;

use Bitrix\Calendar\Internal\Repository\EventRepository;
use Bitrix\Calendar\Synchronization\Internal\Entity\SectionConnection;
use Bitrix\Calendar\Synchronization\Internal\Exception\Messenger\MessageSendingException;
use Bitrix\Calendar\Synchronization\Internal\Service\Logger\RequestLogger;
use Bitrix\Calendar\Synchronization\Internal\Service\Messenger\Sender\EventSender;
use Bitrix\Main\Application;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\Config\ConfigurationException;
use Bitrix\Main\ObjectPropertyException;
use Bitrix\Main\SystemException;

/**
 * Catch-up export of the events that live in a section but were never sent to a vendor.
 *
 * Runs on every delivery of a section created message, retries included, not only on the delivery
 * that created the section connection. The repository returns just the events having no
 * EventConnection with that connection, so a partial failure (push subscription, only a part of the
 * messages enqueued) is repaired by the next delivery of the same message, and the events already
 * exported are left to their own messages: updates and deletions never travel as creations.
 *
 * The path itself bounds the volume: a section created message is the only way in, while the initial
 * connection to a vendor exports every section and event on its own (see
 * CreateConnectionCommandHandler). What is left to catch up are the events that appeared while that
 * message was travelling through the queue - tens of them at worst. Each of them carries its own
 * message anyway, so this export is a second line, repairing the ones whose own message arrived
 * before the section connection existed.
 */
class SectionEventsExporter
{
	/**
	 * Budget of a run, taken an order of magnitude above what can pile up behind a section message.
	 * A selection filling it whole was probably cut short, which is an anomaly and is reported twice:
	 * to the synchronization log, which the calendar.log_enabled option silences by default, and to
	 * the exception log, which it does not.
	 */
	private const EVENTS_PER_RUN = 1000;

	public function __construct(
		private readonly EventRepository $eventRepository,
		private readonly EventSender $eventSender,
		private readonly RequestLogger $logger,
	)
	{
	}

	/**
	 * Enqueues a create message for every event of the section still unsynced with the connection.
	 *
	 * Enqueue errors are intentionally not swallowed: they propagate so the section message stays
	 * recoverable, keeping the invariant "section created => catch-up export enqueued".
	 *
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 * @throws ConfigurationException
	 * @throws MessageSendingException
	 */
	public function exportUnsyncedEvents(SectionConnection $sectionConnection, string $vendorCode): void
	{
		// Event synchronizers send nothing over an inactive section connection.
		if (!$sectionConnection->isActive())
		{
			return;
		}

		$section = $sectionConnection->getSection();
		if ($section === null)
		{
			return;
		}

		$sectionId = $section->getId();
		if ($sectionId === null)
		{
			return;
		}

		$connection = $sectionConnection->getConnection();
		if ($connection === null)
		{
			return;
		}

		$connectionId = $connection->getId();
		if ($connectionId === null)
		{
			return;
		}

		// The create path carries the owner on the section; a connection read from the DB carries it too.
		$userId = (int)($section->getOwner()?->getId() ?? $sectionConnection->getOwner()?->getId() ?? 0);
		if ($userId === 0)
		{
			return;
		}

		$eventIds = $this->eventRepository->getUnsyncedEventIdsBySectionId(
			$sectionId,
			$userId,
			$connectionId,
			self::EVENTS_PER_RUN,
		);

		foreach ($eventIds as $eventId)
		{
			$this->eventSender->sendCreatedMessageForVendor($eventId, $vendorCode);
		}

		if (count($eventIds) === self::EVENTS_PER_RUN)
		{
			$this->reportPossibleTruncation($sectionId, $userId, $vendorCode);
		}
	}

	private function reportPossibleTruncation(int $sectionId, int $userId, string $vendorCode): void
	{
		$message = sprintf(
			'Catch-up export of section "%d" filled its budget of %d events, the selection may be truncated',
			$sectionId,
			self::EVENTS_PER_RUN,
		);

		$this->logger->warning(
			$message,
			[
				'entityId' => $sectionId,
				'userId' => $userId,
				'type' => 'export',
				'vendorCode' => $vendorCode,
			],
		);

		$this->writeToExceptionLog(sprintf('%s (user "%d", vendor "%s")', $message, $userId, $vendorCode));
	}

	/**
	 * The synchronization log keeps quiet until the calendar.log_enabled option is turned on, so an
	 * anomaly also goes to the channel a default installation does read.
	 */
	protected function writeToExceptionLog(string $message): void
	{
		Application::getInstance()->getExceptionHandler()->writeToLog(new SystemException($message));
	}
}
