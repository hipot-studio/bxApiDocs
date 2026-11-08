<?php

declare(strict_types=1);

namespace Bitrix\Calendar\Synchronization\Internal\Service\Vendor\ICloud\Processor;

// TODO: Remove API from sync when we switch to the new one
use Bitrix\Calendar\Core\Base\BaseException;
use Bitrix\Calendar\Core\Base\Date;
use Bitrix\Calendar\Core\Builders\EventCloner;
use Bitrix\Calendar\Core\Event\Event;
use Bitrix\Calendar\Core\Event\EventSaveStateException;
use Bitrix\Calendar\Core\Event\Properties\ExcludedDatesCollection;
use Bitrix\Calendar\Core\Mappers\Event as EventMapper;
use Bitrix\Calendar\Internal\Event\ExclusionSaveTransaction;
use Bitrix\Calendar\Sync\Dictionary;
use Bitrix\Calendar\Synchronization\Internal\Entity\EventConnection;
use Bitrix\Calendar\Synchronization\Internal\Entity\SectionConnection;
use Bitrix\Calendar\Synchronization\Internal\Exception\DtoValidationException;
use Bitrix\Calendar\Synchronization\Internal\Exception\ICloudImportIncompleteException;
use Bitrix\Calendar\Synchronization\Internal\Exception\SynchronizerException;
use Bitrix\Calendar\Synchronization\Internal\Model\EventConnectionTable;
use Bitrix\Calendar\Synchronization\Internal\Repository\EventConnectionRepository;
use Bitrix\Calendar\Synchronization\Internal\Service\Logger\RequestLogger;
use Bitrix\Calendar\Synchronization\Internal\Service\Vendor\ICloud\Dto\EventListResponse;
use Bitrix\Calendar\Synchronization\Internal\Service\Vendor\ICloud\Dto\EventResponse;
use Bitrix\Calendar\Synchronization\Internal\Service\Vendor\ICloud\Processor\Event\ExternalEventData;
use Bitrix\Calendar\Synchronization\Internal\Service\Processor\AbstractEventImportProcessor;
use Bitrix\Calendar\Synchronization\Internal\Service\Vendor\ICloud\Processor\EventConnection\EventConnectionBuilder;
use Bitrix\Calendar\Synchronization\Internal\Service\Processor\Event\EventData;
use Bitrix\Calendar\Synchronization\Internal\Service\Processor\Event\LocalEventMap;
use Bitrix\Calendar\Util;
use Bitrix\Calendar\UserField\ResourceBooking;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\ObjectException;
use Bitrix\Main\ObjectPropertyException;
use Bitrix\Main\Repository\Exception\PersistenceException;
use Bitrix\Main\SystemException;

class EventImportProcessor extends AbstractEventImportProcessor
{
	private const COMPLETION_BATCH_SIZE = 500;

	private array $rawFamilyLinks = [];
	private array $rawFamilyPresence = [];
	private array $rawEventRows = [];
	private array $familyExcludedDates = [];
	private array $touchedEventIds = [];
	private array $completedFamilies = [];
	private array $deferredEventUnlinks = [];
	private array $ownedRawLinks = [];
	private bool $domainSkipped = false;

	public function __construct(
		private readonly EventConnectionBuilder $eventConnectionBuilder,
		private readonly RequestLogger $logger,
		EventConnectionRepository $eventConnectionRepository,
		EventMapper $eventMapper,
	)
	{
		parent::__construct($eventConnectionRepository, $eventMapper);
	}

	/**
	 * @throws ArgumentException
	 * @throws BaseException
	 * @throws DtoValidationException
	 * @throws ObjectException
	 * @throws ObjectPropertyException
	 * @throws PersistenceException
	 * @throws SystemException
	 */
	public function import(SectionConnection $sectionConnection, EventListResponse $eventList): void
	{
		if (ExclusionSaveTransaction::isActive())
		{
			throw new ICloudImportIncompleteException('iCloud import requires its own event-save transaction.');
		}

		$this->isPartialResponse = $eventList->isPartial;
		$this->completedFamilies = [];
		$incomplete = null;

		$ids = $this->getEventConnectionIds($eventList->getItems());

		$this->prepareRawEvidence($ids, $sectionConnection);
		$this->buildLocalEventMap($ids, $sectionConnection);

		$completionCandidates = $this->getCompletionCandidates($eventList->getItems());
		$externalEvents = $this->buildEventsTree($eventList->getItems(), $sectionConnection);
		$familyItemCounts = [];
		foreach ($externalEvents as $vendorId => $externalEventData)
		{
			$familyId = $externalEventData->eventConnection->getRecurrenceId() ?: (string)$vendorId;
			$familyItemCounts[$familyId] = ($familyItemCounts[$familyId] ?? 0) + 1;
		}

		$externalIds = array_keys($externalEvents);
		$completionRows = [];
		$completionRejectedUntil = -1;
		$oldAbsenceProofUntil = -1;
		$oldAbsenceReadUntil = -1;
		$position = 0;
		foreach ($externalEvents as $vendorId => $externalEventData)
		{
			$currentPosition = $position++;
			$this->domainSkipped = false;
			if (is_int($vendorId))
			{
				$this->logger->warning(
					'Unexpected vendor ID type in iCLoud event import: ' . gettype($vendorId),
					[
						'vendorId' => $vendorId,
						'externalEventData' => $externalEventData,
					]
				);
			}

			$familyId = $externalEventData->eventConnection->getRecurrenceId() ?: (string)$vendorId;
			$localEventData = $this->map->get((string)$vendorId);
			if (($familyItemCounts[$familyId] ?? 0) === 1 && isset($completionCandidates[$familyId]))
			{
				$completionBatchOverflow = false;
				if (!$completionRows && $currentPosition > $completionRejectedUntil)
				{
					[$completionRows, $windowEnd, $completionBatchOverflow] = $this->getCompletionBatchRows(
						$externalEvents, $externalIds, $currentPosition, $familyItemCounts,
						$completionCandidates, $sectionConnection,
					);
					$completionRejectedUntil = $windowEnd;
				}
				if (!$completionBatchOverflow && $this->isCompletedFamily(
					$externalEventData, $localEventData, $sectionConnection, $completionCandidates[$familyId],
					$completionRows[$familyId] ?? null,
				))
				{
					unset($completionRows[$familyId]);
					$oldAbsenceProofUntil = -1;

					continue;
				}
			}
			$completionRows = [];
			if ($this->isOldInitialFamily(
				$externalEventData, $localEventData, $eventList,
				$familyItemCounts[$familyId] ?? 0, $completionCandidates[$familyId] ?? null,
			))
			{
				if ($currentPosition > $oldAbsenceReadUntil)
				{
					[$oldFamilyIds, $windowEnd] = $this->getOldInitialFamilyWindow(
						$externalEvents, $externalIds, $currentPosition, $familyItemCounts,
						$completionCandidates, $eventList,
					);
					$hasRawLinks = $this->eventConnectionRepository->hasAnyRawLinksByVendorIds(
						$oldFamilyIds, (int)$sectionConnection->getConnection()->getId(),
					);
					$oldAbsenceReadUntil = $windowEnd;
					$oldAbsenceProofUntil = $hasRawLinks ? -1 : $windowEnd;
				}
				if ($this->isOldInitialFamily(
					$externalEventData, $localEventData, $eventList,
					$familyItemCounts[$familyId] ?? 0, $completionCandidates[$familyId] ?? null,
				) && ($currentPosition <= $oldAbsenceProofUntil
					|| !$this->eventConnectionRepository->getRawLinksByVendorIds(
						[$familyId], (int)$sectionConnection->getConnection()->getId(),
					)))
				{
					continue;
				}
			}
			$oldAbsenceProofUntil = -1;
			$snapshot = $this->copyEventData($this->map->get($familyId));
			$this->touchedEventIds = array_column(array_filter(
				$this->rawFamilyLinks[$familyId] ?? [],
				fn (array $link): bool => $this->isOwnedRawLink($link, $sectionConnection),
			), 'EVENT_ID');
			$cacheKeys = new \ArrayObject();
			$committed = false;
			$newFamilyCompleted = false;
			$isNewFamily = ($familyItemCounts[$familyId] ?? 0) === 1
				&& isset($completionCandidates[$familyId])
				&& $this->isNewFamily($externalEventData, $localEventData, $completionCandidates[$familyId])
			;
			$missingRefusal = null;
			$refusedLinks = [];
			try
			{
				$processed = ExclusionSaveTransaction::runEventSaveTransaction(function () use (
					$externalEventData, $localEventData, $sectionConnection, $eventList, $cacheKeys,
					$isNewFamily, &$newFamilyCompleted,
				): bool {
					$previousCapture = \CCalendarEvent::captureEventSaveListCache($cacheKeys);
					try
					{
						$processed = $this->saveFamily($externalEventData, $localEventData, $sectionConnection, $eventList);
						if ($processed && $isNewFamily)
						{
							$this->completeNewFamily($externalEventData);
							$newFamilyCompleted = true;
						}

						return $processed;
					}
					finally
					{
						\CCalendarEvent::captureEventSaveListCache($previousCapture);
					}
				});
				$committed = true;
			}
			catch (EventSaveStateException $exception)
			{
				$errorCode = $exception->getErrorCode();
				if ($errorCode !== EventSaveStateException::EVENT_NOT_AVAILABLE
					&& $errorCode !== EventSaveStateException::ROOM_RESERVATION_NOT_AVAILABLE)
				{
					throw $exception;
				}
				$missingRefusal = new ICloudImportIncompleteException('iCloud event became unavailable.', 0, $exception);
				if ($errorCode !== EventSaveStateException::EVENT_NOT_AVAILABLE || !$exception->isEventMissing())
				{
					$incomplete = $missingRefusal;

					continue;
				}
				$refusedLinks = $this->rawFamilyLinks[$familyId] ?? [];
			}
			catch (ICloudImportIncompleteException|PersistenceException $exception)
			{
				$incomplete = $exception;

				continue;
			}
			finally
			{
				if (!$committed)
				{
					$this->restoreFamily($familyId, $sectionConnection, $cacheKeys->getArrayCopy());
					unset($externalEvents[$vendorId]);
				}
			}
			if ($missingRefusal !== null)
			{
				if (!$this->isMissingMasterAfterRollback($externalEventData, $familyId, $refusedLinks, $sectionConnection))
				{
					$incomplete = $missingRefusal;

					continue;
				}
				$processed = false;
			}
			if ($this->domainSkipped)
			{
				continue;
			}
			try
			{
				if ($processed && !$newFamilyCompleted)
				{
					$this->pruneFamily($externalEventData, $snapshot, $familyId);
				}
				if (!$newFamilyCompleted)
				{
					$this->completedFamilies[$familyId][(string)$vendorId] = [
						'familyId' => $familyId,
						'external' => $externalEventData,
					];
					$this->acknowledgeFamily($externalEventData, $sectionConnection, $familyId);
				}
			}
			catch (ICloudImportIncompleteException|PersistenceException $exception)
			{
				$incomplete = $exception;
				$this->restoreFamily($familyId, $sectionConnection, []);
				unset($externalEvents[$vendorId]);

				continue;
			}
			if ($processed)
			{
				if (!$externalEventData->isDeleted())
				{
					$updated = new EventData($externalEventData->eventConnection);
					foreach ($externalEventData->getInstances() as $instance)
					{
						$updated->addInstance(new EventData($instance->eventConnection));
					}
					$this->map->add((string)$vendorId, $updated);
				}
			}
		}
		if ($incomplete !== null)
		{
			throw new SynchronizerException('iCloud import contains unfinished changes.', 0, $incomplete);
		}
	}

	private function getCompletionCandidates(array $items): array
	{
		$candidates = [];
		$counts = [];
		foreach ($items as $item)
		{
			$familyId = $item->eventData['RECURRING_EVENT_ID'] ?? $item->id;
			if (!is_string($familyId))
			{
				continue;
			}
			$counts[$familyId] = ($counts[$familyId] ?? 0) + 1;
			if ($familyId !== $item->id || $item->isEmpty() || $item->status !== 200
				|| !is_string($item->eventData['XML_ID'] ?? null) || $item->eventData['XML_ID'] === '')
			{
				continue;
			}
			$dates = [];
			$instances = $item->getRawOverriddenInstancesData();
			foreach ($instances as $instance)
			{
				if (($instance['XML_ID'] ?? null) !== $item->eventData['XML_ID']
					|| ($instance['RECURRING_EVENT_ID'] ?? null) !== $familyId
					|| !is_string($instance['RECURRENCE_ID_DATE'] ?? null) || $instance['RECURRENCE_ID_DATE'] === ''
					|| (isset($instance['TZ_FROM']) && !is_string($instance['TZ_FROM']))
					|| !\CCalendar::timestamp($instance['RECURRENCE_ID_DATE'], false, empty($instance['SKIP_TIME'])))
				{
					continue 2;
				}
				try
				{
					$date = Util::getDateObject(
						$instance['RECURRENCE_ID_DATE'], !empty($instance['SKIP_TIME']), $instance['TZ_FROM'] ?? null,
					)->format('Ymd');
				}
				catch (ObjectException $exception)
				{
					continue 2;
				}
				if (isset($dates[$date]))
				{
					continue 2;
				}
				$dates[$date] = true;
			}
			$candidates[$familyId] = count($item->getOverriddenInstancesData());
		}
		foreach ($candidates as $familyId => $count)
		{
			if ($counts[$familyId] !== 1)
			{
				unset($candidates[$familyId]);
			}
		}

		return $candidates;
	}

	private function isNewFamily(ExternalEventData $externalData, ?EventData $localData, int $instanceCount): bool
	{
		$familyId = $externalData->item->id;
		if ($localData !== null || $this->map->get($familyId) !== null
			|| isset($this->rawFamilyPresence[$familyId]) || $this->deferredEventUnlinks
			|| $externalData->isDeleted() || $externalData->eventConnection->getRecurrenceId() !== null
			|| $externalData->getEvent()->getRecurrenceId() !== null
			|| !empty($externalData->item->eventData['EXDATE'])
			|| $instanceCount !== count($externalData->getInstances()))
		{
			return false;
		}
		foreach (array_merge([$externalData], $externalData->getInstances()) as $data)
		{
			$event = $data->getEvent();
			if (($event->getId() ?? 0) !== 0 || ($event->getParentId() ?? 0) !== 0
				|| $data->eventConnection->getId() !== null
				|| $event->getSpecialLabel() === ResourceBooking::EVENT_LABEL || $data->isDeleted()
				|| $data->item->href !== $externalData->item->href
				|| $data->eventConnection->getVendorEventId() !== $familyId
				|| ($data !== $externalData && $data->eventConnection->getRecurrenceId() !== $familyId))
			{
				return false;
			}
		}

		return true;
	}

	private function isOldInitialFamily(
		ExternalEventData $externalData,
		EventData|false|null $localData,
		EventListResponse $eventList,
		int $familyItemCount,
		?int $completionCandidate,
	): bool
	{
		return !$eventList->nextSyncToken && $familyItemCount === 1
			&& $completionCandidate === 0
			&& !$externalData->item->getRawOverriddenInstancesData()
			&& $localData !== false && $this->isNewFamily($externalData, $localData, 0)
			&& $externalData->item->isOld()
		;
	}

	private function getOldInitialFamilyWindow(
		array $externalEvents,
		array $externalIds,
		int $start,
		array $familyItemCounts,
		array $completionCandidates,
		EventListResponse $eventList,
	): array
	{
		$families = [];
		$windowEnd = $start;
		for ($position = $start; $position < count($externalIds); $position++)
		{
			$vendorId = $externalIds[$position];
			$externalData = $externalEvents[$vendorId];
			$familyId = $externalData->eventConnection->getRecurrenceId() ?: (string)$vendorId;
			if (count($families) >= self::COMPLETION_BATCH_SIZE || !$this->isOldInitialFamily(
				$externalData, $this->map->get((string)$vendorId), $eventList,
				$familyItemCounts[$familyId] ?? 0, $completionCandidates[$familyId] ?? null,
			))
			{
				break;
			}
			$families[] = $familyId;
			$windowEnd = $position;
		}

		return [$families, $windowEnd];
	}

	private function completeNewFamily(ExternalEventData $externalData): void
	{
		foreach (array_merge([$externalData], $externalData->getInstances()) as $data)
		{
			$metadata = $data->eventConnection->getData() ?? [];
			unset($metadata['icloudImportPending']);
			$data->eventConnection
				->setEntityTag($externalData->item->etag)
				->setVendorVersionId($externalData->item->getVersion())
				->setLastSyncStatus(Dictionary::SYNC_STATUS['success'])
				->setData($metadata)
			;
			$this->eventConnectionRepository->save($data->eventConnection);
		}
	}

	private function getCompletionBatchRows(
		array $externalEvents,
		array $externalIds,
		int $start,
		array $familyItemCounts,
		array $completionCandidates,
		SectionConnection $sectionConnection,
	): array
	{
		$families = [];
		$foldedFamilies = [];
		$linkCount = 0;
		$windowEnd = $start;
		$ambiguous = false;
		for ($position = $start; $position < count($externalIds); $position++)
		{
			$vendorId = $externalIds[$position];
			$externalData = $externalEvents[$vendorId];
			$familyId = $externalData->eventConnection->getRecurrenceId() ?: (string)$vendorId;
			if (($familyItemCounts[$familyId] ?? 0) !== 1 || !isset($completionCandidates[$familyId]))
			{
				break;
			}
			$expected = $this->getCompletedFamilyExpectedLinks(
				$externalData, $this->map->get((string)$vendorId), $sectionConnection, $completionCandidates[$familyId],
			);
			if ($expected === null || preg_match('/^[A-Za-z0-9-]+$/D', $familyId) !== 1
				|| count($families) >= self::COMPLETION_BATCH_SIZE
				|| $linkCount + count($expected) > self::COMPLETION_BATCH_SIZE)
			{
				break;
			}
			$foldedId = strtolower($familyId);
			$ambiguous = $ambiguous || isset($foldedFamilies[$foldedId]);
			$foldedFamilies[$foldedId] = $familyId;
			$families[$familyId] = count($expected);
			$linkCount += count($expected);
			$windowEnd = $position;
		}
		if (!$families || $ambiguous)
		{
			return [[], $windowEnd, false];
		}
		$rows = $this->eventConnectionRepository->getCurrentImportFamiliesRows(
			array_keys($families), (int)$sectionConnection->getConnection()->getId(),
			(int)$sectionConnection->getSection()?->getId(),
		);
		if (count($rows) > self::COMPLETION_BATCH_SIZE)
		{
			return [[], $windowEnd, true];
		}
		$grouped = array_fill_keys(array_keys($families), []);
		foreach ($rows as $row)
		{
			$matched = false;
			foreach (['VENDOR_EVENT_ID', 'RECURRENCE_ID'] as $field)
			{
				$id = $row[$field];
				if ($id === null || $id === '')
				{
					continue;
				}
				if (!is_string($id) || preg_match('/^[A-Za-z0-9-]+$/D', $id) !== 1)
				{
					return [[], $windowEnd, false];
				}
				$foldedId = strtolower($id);
				if (isset($foldedFamilies[$foldedId]) && $foldedFamilies[$foldedId] !== $id)
				{
					return [[], $windowEnd, false];
				}
				if (isset($families[$id]))
				{
					$grouped[$id][(int)$row['ID']] = $row;
					$matched = true;
				}
			}
			if (!$matched)
			{
				return [[], $windowEnd, false];
			}
		}
		foreach ($families as $familyId => $expectedCount)
		{
			if (count($grouped[$familyId]) !== $expectedCount)
			{
				return [[], $windowEnd, false];
			}
			$grouped[$familyId] = array_values($grouped[$familyId]);
		}

		return [$grouped, $windowEnd, false];
	}

	private function getCompletedFamilyExpectedLinks(
		ExternalEventData $externalData,
		?EventData $localData,
		SectionConnection $sectionConnection,
		int $instanceCount,
		bool $allowLargeFamily = false,
	): ?array
	{
		$familyId = $externalData->item->id;
		if ($localData === null || $externalData->isDeleted()
			|| $externalData->eventConnection->getRecurrenceId() !== null
			|| $localData->getEvent()->isInstance() || !$localData->getEvent()->canBeChanged()
			|| $localData->getEvent()->isRecurrence() !== $externalData->getEvent()->isRecurrence()
			|| $instanceCount !== count($externalData->getInstances())
			|| $instanceCount !== count($localData->getInstances())
			|| !empty($this->familyExcludedDates[$familyId]) || $this->deferredEventUnlinks)
		{
			return null;
		}
		$links = $this->rawFamilyLinks[$familyId] ?? [];
		if (count($links) !== $instanceCount + 1 || !$links
			|| (!$allowLargeFamily && count($links) > self::COMPLETION_BATCH_SIZE))
		{
			return null;
		}
		$expected = [];
		foreach (array_merge([$localData], $localData->getInstances()) as $data)
		{
			$linkId = (int)$data->eventConnection->getId();
			if ($linkId <= 0 || isset($expected[$linkId]) || !$data->getEvent()->canBeChanged()
				|| $data->eventConnection->getVendorEventId() !== $familyId
				|| $data->eventConnection->getLastSyncStatus() !== Dictionary::SYNC_STATUS['success'])
			{
				return null;
			}
			$expected[$linkId] = $data;
		}
		foreach ($externalData->getInstances() as $key => $instance)
		{
			$localInstance = $localData->getInstances()[$key] ?? null;
			if ($localInstance === null || $instance->item->href !== $externalData->item->href
				|| $instance->eventConnection->getRecurrenceId() !== $familyId
				|| $instance->getEvent()->getOriginalDateFrom()?->format('YmdHisO')
					!== $localInstance->getEvent()->getOriginalDateFrom()?->format('YmdHisO')
				|| $instance->getEvent()->isFullDayEvent() !== $localInstance->getEvent()->isFullDayEvent()
				|| $instance->getEvent()->getStartTimeZone()?->toString()
					!== $localInstance->getEvent()->getStartTimeZone()?->toString())
			{
				return null;
			}
		}
		foreach ($links as $link)
		{
			if (!isset($expected[(int)$link['ID']]) || !$this->isOwnedRawLink($link, $sectionConnection)
				|| $link['ENTITY_TAG'] !== $externalData->item->etag
				|| $link['VENDOR_VERSION_ID'] !== $externalData->item->getVersion()
				|| array_key_exists('icloudImportPending', $link['DATA'] ?? []))
			{
				return null;
			}
		}

		return $expected;
	}

	private function isCompletedFamily(
		ExternalEventData $externalData,
		?EventData $localData,
		SectionConnection $sectionConnection,
		int $instanceCount,
		?array $rows = null,
	): bool
	{
		$expected = $this->getCompletedFamilyExpectedLinks(
			$externalData, $localData, $sectionConnection, $instanceCount, allowLargeFamily: $rows === null,
		);
		if ($expected === null)
		{
			return false;
		}
		$familyId = $externalData->item->id;
		$expectedCount = count($expected);
		$isLargeFamily = $rows === null && $expectedCount > self::COMPLETION_BATCH_SIZE;
		// Only this fresh observation proves completion; earlier snapshots merely select candidates.
		if ($isLargeFamily)
		{
			$rows = $this->eventConnectionRepository->getCurrentImportFamilyCompletionRows(
				$familyId, (int)$sectionConnection->getConnection()->getId(),
				(int)$sectionConnection->getSection()?->getId(), $expectedCount,
			);
		}
		else
		{
			$rows ??= $this->eventConnectionRepository->getCurrentImportFamiliesRows(
				[$familyId], (int)$sectionConnection->getConnection()->getId(),
				(int)$sectionConnection->getSection()?->getId(),
			);
		}
		if (count($rows) !== $expectedCount || (!$isLargeFamily && count($rows) > self::COMPLETION_BATCH_SIZE))
		{
			return false;
		}
		$dataField = EventConnectionTable::getEntity()->getField('DATA');
		$eventIds = [];
		foreach ($rows as $row)
		{
			$linkId = (int)$row['ID'];
			$data = $expected[$linkId] ?? null;
			$eventId = (int)$row['EVENT_ID'];
			if ($data === null || isset($eventIds[$eventId]) || $eventId <= 0
				|| $eventId !== (int)$data->getEvent()->getId() || $eventId !== (int)$row['EVENT_ROW_ID']
				|| $eventId !== (int)$row['EVENT_PARENT_ID'] || $row['EVENT_DELETED'] !== 'N'
				|| (int)$row['CONNECTION_ID'] !== (int)$sectionConnection->getConnection()->getId()
				|| (int)$row['EVENT_SECTION_ID'] !== (int)$sectionConnection->getSection()?->getId()
				|| $row['EVENT_SPECIAL_LABEL'] === ResourceBooking::EVENT_LABEL
				|| $row['VENDOR_EVENT_ID'] !== $data->eventConnection->getVendorEventId()
				|| $row['RECURRENCE_ID'] !== $data->eventConnection->getRecurrenceId()
				|| $row['ENTITY_TAG'] !== $externalData->item->etag
				|| $row['VENDOR_VERSION_ID'] !== $externalData->item->getVersion()
				|| $row['SYNC_STATUS'] !== Dictionary::SYNC_STATUS['success'])
			{
				return false;
			}
			$oldRow = $this->rawEventRows[$eventId] ?? null;
			if ($oldRow === null || (int)$row['EVENT_RECURRENCE_ID'] !== (int)$oldRow['RECURRENCE_ID']
				|| $row['EVENT_TZ_FROM'] !== $oldRow['TZ_FROM']
				|| $row['EVENT_DT_SKIP_TIME'] !== $oldRow['DT_SKIP_TIME']
				|| $this->getCompletionDate($row['EVENT_ORIGINAL_DATE_FROM'])
					!== $this->getCompletionDate($oldRow['ORIGINAL_DATE_FROM'])
				|| ($data === $localData ? !empty($row['RECURRENCE_ID'])
					: ($row['RECURRENCE_ID'] !== $familyId
						|| (int)$row['EVENT_RECURRENCE_ID'] !== (int)$localData->getEvent()->getId())))
			{
				return false;
			}
			try
			{
				$metadata = $dataField->decode($row['DATA']);
				if (!is_array($metadata) || array_key_exists('icloudImportPending', $metadata)
					|| $row['DATA'] !== $dataField->encode($metadata))
				{
					return false;
				}
			}
			catch (ArgumentException $exception)
			{
				return false;
			}
			$eventIds[$eventId] = true;
			unset($expected[$linkId]);
		}

		return !$expected;
	}

	private function getCompletionDate(mixed $date): ?string
	{
		return $date instanceof \Bitrix\Main\Type\Date ? $date->format('Y-m-d H:i:s') : $date;
	}

	private function isMissingMasterAfterRollback(
		ExternalEventData $externalData,
		string $familyId,
		array $refusedLinks,
		SectionConnection $sectionConnection,
	): bool
	{
		if (($externalData->eventConnection->getRecurrenceId() ?: null) !== null || $externalData->item->id !== $familyId)
		{
			return false;
		}
		$connectionId = (int)$sectionConnection->getConnection()->getId();
		$masters = [];
		foreach ([$refusedLinks, $this->rawFamilyLinks[$familyId] ?? []] as $links)
		{
			$matches = array_values(array_filter($links, static function (array $link) use ($familyId, $connectionId): bool {
				return (int)$link['CONNECTION_ID'] === $connectionId && $link['VENDOR_EVENT_ID'] === $familyId
					&& ($link['RECURRENCE_ID'] ?: null) === null;
			}));
			if (count($matches) !== 1)
			{
				return false;
			}
			$masters[] = $matches[0];
		}
		if ((int)$masters[0]['ID'] !== (int)$masters[1]['ID']
			|| (int)$masters[0]['EVENT_ID'] !== (int)$masters[1]['EVENT_ID'])
		{
			return false;
		}
		$row = $this->rawEventRows[(int)$masters[1]['EVENT_ID']] ?? null;

		return $row === null || ($row['DELETED'] === 'Y'
			&& (int)$row['SECTION_ID'] === (int)$sectionConnection->getSection()?->getId());
	}

	private function acknowledgeFamily(
		ExternalEventData $externalData,
		SectionConnection $sectionConnection,
		string $familyId,
	): void
	{
		if (ExclusionSaveTransaction::isActive())
		{
			throw new ICloudImportIncompleteException('iCloud acknowledgement requires its own transaction.');
		}
		$connectionId = (int)$sectionConnection->getConnection()->getId();
		$completedItems = [];
		$masterItem = null;
		foreach ($this->completedFamilies[$familyId] ?? [] as $completed)
		{
			if ($completed['familyId'] !== $familyId)
			{
				continue;
			}
			$data = $completed['external'];
			$completedItems[$data->item->id] = $data->item;
			if (($data->eventConnection->getRecurrenceId() ?: null) === null && $data->item->id === $familyId
				&& !$this->isPartialResponse)
			{
				$masterItem = $data->item;
			}
		}
		$links = ExclusionSaveTransaction::runEventSaveTransaction(function () use (
			$connectionId, $familyId, $completedItems, $masterItem, $sectionConnection,
		): array {
			$links = $this->getScopedRawLinks([$familyId], $sectionConnection, true);
			$pendingOwnerIds = [];
			foreach ($links as $link)
			{
				if (!$this->isOwnedRawLink($link, $sectionConnection))
				{
					throw new ICloudImportIncompleteException('iCloud acknowledgement ownership is not proven.');
				}
				if ((int)$link['CONNECTION_ID'] !== $connectionId
					|| ($link['VENDOR_EVENT_ID'] !== $familyId && $link['RECURRENCE_ID'] !== $familyId))
				{
					throw new PersistenceException('iCloud acknowledgement family identity does not match.');
				}
				if (isset($completedItems[$link['VENDOR_EVENT_ID']]))
				{
					$pendingOwnerIds[] = (int)$link['ID'];
				}
			}
			$this->eventConnectionRepository->prepareImportLinkAcknowledgements(
				array_column($links, 'ID'), $connectionId, $familyId,
				$masterItem === null ? $pendingOwnerIds : null,
			);
			foreach ($links as &$link)
			{
				$item = $completedItems[$link['VENDOR_EVENT_ID']] ?? $masterItem;
				$this->eventConnectionRepository->acknowledgeImportLink(
					(int)$link['ID'], $connectionId, $familyId,
					$item?->etag, $item?->getVersion(), $item === null,
				);
				if ($item !== null)
				{
					$link['ENTITY_TAG'] = $item->etag;
					$link['VENDOR_VERSION_ID'] = $item->getVersion();
				}
				unset($link['DATA']['icloudImportPending']);
			}
			unset($link);

			return $links;
		});
		$this->rawFamilyLinks[$familyId] = $links;
		foreach (array_merge([$externalData], $externalData->getInstances()) as $data)
		{
			$metadata = $data->eventConnection->getData() ?? [];
			unset($metadata['icloudImportPending']);
			$data->eventConnection
				->setEntityTag($externalData->item->etag)
				->setVendorVersionId($externalData->item->getVersion())
				->setLastSyncStatus(Dictionary::SYNC_STATUS['success'])
				->setData($metadata)
			;
		}
	}

	private function saveFamily(
		ExternalEventData $externalData,
		?EventData $localData,
		SectionConnection $sectionConnection,
		EventListResponse $eventList,
	): bool
	{
		if (!$this->prepareFamily($externalData, $sectionConnection))
		{
			return false;
		}
		if (!$this->shouldEventBeProceed($externalData, $localData, $eventList->nextSyncToken))
		{
			return false;
		}
		if ($externalData->isDeleted())
		{
			$this->enrichExternalData($externalData);
			if ($externalData->eventConnection->getRecurrenceId() !== null)
			{
				$master = $this->getMasterLocalEvent($externalData->eventConnection->getRecurrenceId());
				$localInstance = $this->map->get($externalData->eventConnection->getVendorEventId());
				if ($localInstance === null || !$localInstance->getEvent()->isInstance())
				{
					$localInstance = $master?->getSameInstance($externalData);
				}
				if ($localInstance !== null && $localInstance->getEvent()->isInstance())
				{
					$externalData->getEvent()
						->setRecurrenceId($localInstance->getEvent()->getRecurrenceId())
						->setOriginalDateFrom($localInstance->getEvent()->getOriginalDateFrom())
					;
				}
				if ($master === null || !$master->getEvent()->canBeChanged()
					|| $externalData->getEvent()->getOriginalDateFrom() === null)
				{
					throw new ICloudImportIncompleteException('iCloud deleted instance has no writable master exclusion.');
				}
				$this->addExcludedDateToMasterEvent($master->getEvent(), $externalData->getEvent());
				$this->saveEventData($master);
			}
			if ($externalData->getEvent()->getId() !== null)
			{
				$this->eventConnectionRepository->save($externalData->eventConnection);
			}

			return true;
		}

		$this->saveEventData($externalData);
		foreach ($externalData->getInstances() as $instance)
		{
			$this->saveEventData($instance, $externalData);
		}

		return true;
	}

	private function pruneFamily(ExternalEventData $externalData, ?EventData $snapshot, string $familyId): void
	{
		$this->deferredEventUnlinks = [];
		$deleteFailure = null;
		try
		{
			if ($externalData->isDeleted() && $externalData->getEvent()->getId() !== null)
			{
				$this->deleteEvent($externalData->eventConnection);
			}
			$isMasterItem = ($externalData->eventConnection->getRecurrenceId() ?: null) === null
				&& $externalData->eventConnection->getVendorEventId() === $familyId;
			if ($snapshot !== null && $isMasterItem
				&& ($externalData->isDeleted() || $externalData->getEvent()->isRecurrence()))
			{
				$instances = $snapshot->getInstances();
				$evidenceIds = [];
				foreach ($this->rawFamilyLinks[$familyId] ?? [] as $link)
				{
					$row = $this->rawEventRows[(int)$link['EVENT_ID']] ?? null;
					if ($row === null || $row['DELETED'] === 'Y')
					{
						$evidenceIds[(int)$link['EVENT_ID']] = true;
					}
				}
				foreach ($instances as $key => $instance)
				{
					if (isset($evidenceIds[(int)$instance->getEvent()->getId()]))
					{
						unset($instances[$key]);
					}
				}
				$snapshot->setInstances($instances);
				$this->removeDeprecatedInstances($snapshot, $externalData);
			}
		}
		catch (\Throwable $exception)
		{
			$deleteFailure = $exception;
		}
		try
		{
			$complete = $this->finishEventUnlinks();
		}
		finally
		{
			if ($deleteFailure !== null)
			{
				throw $deleteFailure;
			}
		}
		if (!$complete)
		{
			throw new ICloudImportIncompleteException('iCloud native deletion did not finish.');
		}
	}

	private function finishEventUnlinks(): bool
	{
		$complete = true;
		try
		{
			$rows = $this->eventConnectionRepository->getRawEventRowsByIds(array_map(
				static fn (EventConnection $link): int => (int)$link->getEvent()->getId(),
				$this->deferredEventUnlinks,
			));
			foreach ($this->deferredEventUnlinks as $link)
			{
				$row = $rows[(int)$link->getEvent()->getId()] ?? null;
				if ($row !== null && $row['DELETED'] !== 'Y')
				{
					$complete = false;

					continue;
				}
				$this->unlinkEventConnection($link);
			}
		}
		finally
		{
			$this->deferredEventUnlinks = [];
		}

		return $complete;
	}

	protected function shouldDeferEventUnlink(): bool
	{
		return true;
	}

	protected function onEventDeleteResult(EventConnection $eventConnection, ?Event $deletedEvent): void
	{
		$this->deferredEventUnlinks[(int)$eventConnection->getId()] = $eventConnection;
	}

	private function copyEventData(?EventData $data): ?EventData
	{
		if ($data === null)
		{
			return null;
		}
		$source = clone $data->getEvent();
		$source->setExcludedDateCollection($source->getExcludedDateCollection() ?? new ExcludedDatesCollection());
		$event = (new EventCloner($source))->build();
		$dates = new ExcludedDatesCollection();
		foreach ($source->getExcludedDateCollection() as $date)
		{
			$dates->add(Date::createDateTimeFromFormat($date->format('YmdHisO'), 'YmdHisO'));
		}
		$event->setExcludedDateCollection($dates);
		$copy = new EventData((clone $data->eventConnection)->setEvent($event));
		foreach ($data->getInstances() as $instance)
		{
			$copy->addInstance($this->copyEventData($instance));
		}

		return $copy;
	}

	private function restoreFamily(string $familyId, SectionConnection $sectionConnection, array $cacheKeys): void
	{
		foreach (array_unique($this->touchedEventIds) as $eventId)
		{
			$this->eventMapper->resetCacheById((int)$eventId);
		}
		\CCalendarEvent::restoreEventSaveListCache(
			$this->touchedEventIds,
			$cacheKeys,
			(int)$sectionConnection->getSection()?->getOwner()?->getId(),
		);
		$oldLinks = $this->rawFamilyLinks[$familyId] ?? [];
		foreach (array_column($oldLinks, 'EVENT_ID') as $eventId)
		{
			unset($this->rawEventRows[(int)$eventId]);
		}
		$this->rawFamilyLinks[$familyId] = $this->getScopedRawLinks([$familyId], $sectionConnection);
		$vendorIds = array_unique(array_merge([$familyId], array_column($oldLinks, 'VENDOR_EVENT_ID')));
		$refreshed = new LocalEventMap(
			$this->eventConnectionRepository->getByIdsAndSectionConnection([$familyId], $sectionConnection),
		);
		foreach ($vendorIds as $vendorId)
		{
			$this->map->remove($vendorId);
			if ($data = $refreshed->get($vendorId))
			{
				$this->map->add($vendorId, $data);
			}
		}
		unset($this->familyExcludedDates[$familyId]);
	}

	private function prepareRawEvidence(array $ids, SectionConnection $sectionConnection): void
	{
		$this->rawFamilyLinks = [];
		$this->rawFamilyPresence = [];
		$this->rawEventRows = [];
		$this->ownedRawLinks = [];
		$this->familyExcludedDates = [];
		foreach ($this->getScopedRawLinks($ids, $sectionConnection) as $link)
		{
			$familyId = $link['RECURRENCE_ID'] ?: $link['VENDOR_EVENT_ID'];
			$this->rawFamilyLinks[$familyId][] = $link;
		}
	}

	private function getScopedRawLinks(
		array $ids,
		SectionConnection $sectionConnection,
		bool $includeEventRows = false,
	): array
	{
		$connectionId = (int)$sectionConnection->getConnection()->getId();
		$sectionId = (int)$sectionConnection->getSection()?->getId();
		$requestedIds = [];
		foreach ($ids as $id)
		{
			$requestedIds[gettype($id) . ':' . $id] = true;
		}
		$links = array_filter(
			$includeEventRows
				? $this->eventConnectionRepository->getRawImportFamilyRows($ids[0], $connectionId)
				: $this->eventConnectionRepository->getRawLinksByVendorIds($ids, $connectionId),
			static function (array $link) use ($connectionId, $requestedIds): bool {
				$familyId = $link['RECURRENCE_ID'] ?: $link['VENDOR_EVENT_ID'];

				return (int)$link['CONNECTION_ID'] === $connectionId
					&& (isset($requestedIds[gettype($link['VENDOR_EVENT_ID']) . ':' . $link['VENDOR_EVENT_ID']])
						|| isset($requestedIds[gettype($familyId) . ':' . $familyId]));
			},
		);
		foreach ($links as $link)
		{
			$this->rawFamilyPresence[$link['VENDOR_EVENT_ID']] = true;
			$this->rawFamilyPresence[$link['RECURRENCE_ID'] ?: $link['VENDOR_EVENT_ID']] = true;
		}
		$rows = [];
		if ($includeEventRows)
		{
			foreach ($links as &$link)
			{
				$row = [];
				foreach (['ID', 'DELETED', 'SECTION_ID', 'PARENT_ID', 'RECURRENCE_ID',
					'ORIGINAL_DATE_FROM', 'TZ_FROM', 'DT_SKIP_TIME'] as $field)
				{
					$alias = $field === 'ID' ? 'EVENT_ROW_ID' : 'EVENT_' . $field;
					$row[$field] = $link[$alias];
					unset($link[$alias]);
				}
				if ($row['ID'] !== null)
				{
					$rows[(int)$row['ID']] = $row;
				}
			}
			unset($link);
		}
		else
		{
			$rows = $this->eventConnectionRepository->getRawEventRowsByIds(array_column($links, 'EVENT_ID'));
		}
		$scopedLinks = [];
		foreach ($links as $link)
		{
			$linkId = (int)$link['ID'];
			$eventId = (int)$link['EVENT_ID'];
			$familyId = $link['RECURRENCE_ID'] ?: $link['VENDOR_EVENT_ID'];
			$row = $rows[$eventId] ?? null;
			unset($this->rawEventRows[$eventId]);
			if ($row !== null)
			{
				if ((int)$row['SECTION_ID'] !== $sectionId)
				{
					unset($this->ownedRawLinks[$linkId]);

					continue;
				}
				$this->rawEventRows[$eventId] = $row;
				$this->ownedRawLinks[$linkId] = [$eventId, $connectionId, $sectionId, $familyId];
			}
			$scopedLinks[] = $link;
		}

		return $scopedLinks;
	}

	private function isOwnedRawLink(array $link, SectionConnection $sectionConnection): bool
	{
		return ($this->ownedRawLinks[(int)$link['ID']] ?? null) === [
			(int)$link['EVENT_ID'],
			(int)$sectionConnection->getConnection()->getId(),
			(int)$sectionConnection->getSection()?->getId(),
			$link['RECURRENCE_ID'] ?: $link['VENDOR_EVENT_ID'],
		];
	}

	private function prepareFamily(ExternalEventData $externalData, SectionConnection $sectionConnection): bool
	{
		$familyId = $externalData->eventConnection->getRecurrenceId()
			?: $externalData->eventConnection->getVendorEventId();
		$links = $this->rawFamilyLinks[$familyId] ?? [];
		$sectionId = (int)$sectionConnection->getSection()?->getId();
		$masterLink = null;
		foreach ($links as $link)
		{
			if (!$this->isOwnedRawLink($link, $sectionConnection))
			{
				throw new ICloudImportIncompleteException('iCloud event ownership is not proven.');
			}
			$row = $this->rawEventRows[(int)$link['EVENT_ID']] ?? null;
			if ($row !== null && (int)$row['SECTION_ID'] !== $sectionId)
			{
				throw new ICloudImportIncompleteException('iCloud event section does not match.');
			}
			if (!$link['RECURRENCE_ID'] && $link['VENDOR_EVENT_ID'] === $familyId)
			{
				if ($masterLink !== null)
				{
					throw new ICloudImportIncompleteException('Ambiguous iCloud master identity.');
				}
				$masterLink = $link;
			}
		}
		if ($masterLink !== null)
		{
			$masterRow = $this->rawEventRows[(int)$masterLink['EVENT_ID']] ?? null;
			if ($masterRow === null || $masterRow['DELETED'] === 'Y')
			{
				return false;
			}
		}

		$instances = $externalData->getInstances();
		$candidateIndex = null;
		$removedInstances = [];
		foreach ($links as $link)
		{
			$row = $this->rawEventRows[(int)$link['EVENT_ID']] ?? null;
			if ($row !== null && $row['DELETED'] !== 'Y')
			{
				continue;
			}
			if (!$link['RECURRENCE_ID'])
			{
				continue;
			}
			$master = $this->map->get($familyId);
			if ($masterLink === null || $master === null || !$master->getEvent()->canBeChanged())
			{
				throw new ICloudImportIncompleteException('iCloud deleted instance has no writable live master.');
			}
			if ($candidateIndex === null)
			{
				$candidates = $instances;
				if ($externalData->eventConnection->getRecurrenceId() === $familyId)
				{
					$candidates[] = $externalData;
				}
				$candidateIndex = [
					'instances' => $candidates,
					'dates' => [],
					'vendors' => null,
					'days' => null,
				];
			}
			[$date, $matchingInstances] = $this->getDeletedInstanceDate($link, $row, $masterLink, $candidateIndex);
			foreach ($matchingInstances as $key)
			{
				if (isset($removedInstances[$key]))
				{
					throw new ICloudImportIncompleteException('Ambiguous iCloud deleted instance identity.');
				}
				$removedInstances[$key] = true;
			}
			$this->familyExcludedDates[$familyId][] = $date;
		}

		if (
			$links
			&& $this->map->get($familyId) === null
			&& $this->map->get($externalData->eventConnection->getVendorEventId()) === null
		)
		{
			throw new ICloudImportIncompleteException('iCloud active event is unavailable for import.');
		}
		foreach ($removedInstances as $key => $removed)
		{
			if (!isset($instances[$key]))
			{
				$master = $this->map->get($familyId);
				$orphanLinks = array_values(array_filter($links, static function (array $link) use ($externalData): bool {
					return $link['VENDOR_EVENT_ID'] === $externalData->item->id
						&& $link['RECURRENCE_ID'] === $externalData->eventConnection->getRecurrenceId();
				}));
				if (count($orphanLinks) !== 1)
				{
					throw new ICloudImportIncompleteException('Ambiguous iCloud orphan pending identity.');
				}
				$localInstance = $this->map->get($externalData->eventConnection->getVendorEventId());
				if ($localInstance !== null && !$localInstance->getEvent()->canBeChanged())
				{
					throw new ICloudImportIncompleteException('iCloud immutable instance has an unfinished exclusion.');
				}
				$orphanLinkId = (int)$orphanLinks[0]['ID'];
				$connectionId = (int)$sectionConnection->getConnection()->getId();
				$this->eventConnectionRepository->prepareImportLinkAcknowledgements(
					[$orphanLinkId], $connectionId, $familyId,
				);
				$this->eventConnectionRepository->markPreparedImportLinkPending($orphanLinkId, $connectionId, $familyId);
				$this->saveEventData($master);

				return false;
			}
			unset($instances[$key]);
		}
		$excludedDates = $externalData->getEvent()->getExcludedDateCollection();
		$externalData->setInstances($instances);
		$externalData->getEvent()->setExcludedDateCollection($excludedDates);

		return true;
	}

	private function getDeletedInstanceDate(array $link, ?array $row, array $masterLink, array &$candidateIndex): array
	{
		$familyId = $link['RECURRENCE_ID'];
		$date = null;
		if ($row !== null)
		{
			if (
				(int)$row['RECURRENCE_ID'] !== (int)$masterLink['EVENT_ID']
				|| empty($row['ORIGINAL_DATE_FROM'])
				|| ($row['DT_SKIP_TIME'] !== 'Y' && !Util::isTimezoneValid($row['TZ_FROM']))
			)
			{
				throw new ICloudImportIncompleteException('iCloud deleted instance recurrence evidence is incomplete.');
			}
			try
			{
				$originalDate = $row['ORIGINAL_DATE_FROM'];
				$originalDate = $originalDate instanceof \Bitrix\Main\Type\Date
					? $originalDate->format(\Bitrix\Main\Type\Date::convertFormatToPhp(FORMAT_DATETIME))
					: (string)$originalDate;
				if (!\CCalendar::timestamp($originalDate, false, $row['DT_SKIP_TIME'] !== 'Y'))
				{
					throw new ICloudImportIncompleteException('Invalid iCloud deleted instance date.');
				}
				$date = new Date(Util::getDateObject(
					$originalDate,
					$row['DT_SKIP_TIME'] === 'Y',
					$row['TZ_FROM'],
				));
			}
			catch (ObjectException $exception)
			{
				throw new ICloudImportIncompleteException('Invalid iCloud deleted instance date.', 0, $exception);
			}
		}
		$getOriginalDate = static function (int|string $key) use (&$candidateIndex, $familyId): ?Date {
			if (!array_key_exists($key, $candidateIndex['dates']))
			{
				$instance = $candidateIndex['instances'][$key];
				$fullDay = $instance->getEvent()->isFullDayEvent();
				$candidateIndex['dates'][$key] = null;
				if (
					$instance->eventConnection->getRecurrenceId() === $familyId
					&& !empty($instance->item->eventData['RECURRENCE_ID_DATE'])
					&& ($fullDay || Util::isTimezoneValid($instance->item->eventData['TZ_FROM'] ?? null))
					&& \CCalendar::timestamp($instance->item->eventData['RECURRENCE_ID_DATE'], false, !$fullDay)
				)
				{
					$candidateIndex['dates'][$key] = $instance->getEvent()->getOriginalDateFrom();
				}
			}

			return $candidateIndex['dates'][$key];
		};
		$matches = [];
		if ($date === null)
		{
			$vendorId = $link['VENDOR_EVENT_ID'];
			if ($candidateIndex['vendors'] === null)
			{
				$candidateIndex['vendors'] = [];
				foreach ($candidateIndex['instances'] as $key => $instance)
				{
					$candidateVendorId = $instance->eventConnection->getVendorEventId();
					$candidateIndex['vendors'][':' . $candidateVendorId][] = $key;
					if ($candidateVendorId === $vendorId)
					{
						$originalDate = $getOriginalDate($key);
						if ($originalDate !== null)
						{
							$matches[$key] = $originalDate;
						}
					}
				}
			}
			else
			{
				foreach ($candidateIndex['vendors'][':' . $vendorId] ?? [] as $key)
				{
					$originalDate = $getOriginalDate($key);
					if ($originalDate !== null)
					{
						$matches[$key] = $originalDate;
					}
				}
			}
		}
		else
		{
			if ($candidateIndex['days'] === null)
			{
				$candidateIndex['days'] = [];
				foreach ($candidateIndex['instances'] as $key => $instance)
				{
					$originalDate = $getOriginalDate($key);
					if ($originalDate !== null)
					{
						$candidateIndex['days'][$originalDate->format('Ymd')][$key] = $originalDate;
					}
				}
			}
			$matches = $candidateIndex['days'][$date->format('Ymd')] ?? [];
		}
		if ($date === null)
		{
			if (count($matches) !== 1)
			{
				throw new ICloudImportIncompleteException('iCloud missing instance date is not uniquely proven.');
			}
			$date = clone reset($matches);
		}

		return [$date, array_keys($matches)];
	}

	/**
	 * @param EventResponse[] $eventResponses
	 *
	 * @return string[]
	 */
	private function getEventConnectionIds(array $eventResponses): array
	{
		$ids = [];

		foreach ($eventResponses as $eventResponse)
		{
			$ids[] = $eventResponse->id;
			if (!empty($eventResponse->eventData['RECURRING_EVENT_ID']))
			{
				$ids[] = $eventResponse->eventData['RECURRING_EVENT_ID'];
			}
		}

		return array_unique($ids);
	}

	/**
	 * @param EventResponse[] $items
	 * @param SectionConnection $sectionConnection
	 *
	 * @return ExternalEventData[]
	 *
	 * @throws ArgumentException
	 * @throws DtoValidationException
	 * @throws ObjectException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	private function buildEventsTree(array $items, SectionConnection $sectionConnection): array
	{
		$tree = [];

		$orphans = [];
		$excludedDates = [];

		foreach ($items as $item)
		{
			$eventConnection = $this->eventConnectionBuilder->build($item, $sectionConnection);

			$externalData = new ExternalEventData($item, $eventConnection);

			$tree[$item->id] = $externalData;
			$dates = $externalData->getEvent()->getExcludedDateCollection();
			$excludedDates[$item->id] = $dates !== null ? clone $dates : null;

			$this->addInstancesToTree($item, $sectionConnection, $tree, $orphans);
		}

		$tree = $this->addOrphansToTree($orphans, $tree);

		foreach ($tree as $externalData)
		{
			$vendorId = $externalData->eventConnection->getVendorEventId();
			if (array_key_exists($vendorId, $excludedDates))
			{
				$externalData->getEvent()->setExcludedDateCollection($excludedDates[$vendorId]);
			}
			foreach ($externalData->getInstances() as $instance)
			{
				$data = $instance->eventConnection->getData() ?? [];
				unset($data['icloudImportPending']);
				$instance->eventConnection->setData($data);
			}
		}

		return $tree;
	}

	/**
	 * @throws ArgumentException
	 * @throws DtoValidationException
	 * @throws ObjectException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	private function addInstancesToTree(
		EventResponse $masterItem,
		SectionConnection $sectionConnection,
		array $tree,
		array &$orphans,
	): void
	{
		$id = $masterItem->id;

		$instancesData = $masterItem->getOverriddenInstancesData();

		foreach ($instancesData as $instanceData)
		{
			$instanceItem = new EventResponse(
				$masterItem->href,
				$masterItem->etag,
				$masterItem->status,
				$instanceData,
			);

			$recurringEventId = $instanceData['RECURRING_EVENT_ID'] ?? null;

			if ($recurringEventId === null)
			{
				continue;
			}

			$eventConnection = $this->eventConnectionBuilder->build($instanceItem, $sectionConnection);

			$externalData = new ExternalEventData($instanceItem, $eventConnection);

			if (isset($tree[$recurringEventId]))
			{
				$tree[$recurringEventId]->addInstance($externalData);

				if (!empty($orphans[$id]))
				{
					unset($orphans[$id]);
				}
			}
			else
			{
				$orphans[$recurringEventId][$id] = $externalData;
			}
		}
	}

	/**
	 * @param array $orphans
	 * @param ExternalEventData[] $tree
	 *
	 * @return ExternalEventData[]
	 */
	private function addOrphansToTree(array $orphans, array $tree): array
	{
		foreach ($orphans as $items)
		{
			/** @var ExternalEventData $externalEventData */
			foreach ($items as $externalEventData)
			{
				$tree[$externalEventData->eventConnection->getVendorEventId()] = $externalEventData;
			}
		}

		return $tree;
	}

	private function shouldEventBeProceed(
		ExternalEventData $externalEventData,
		?EventData $localEventData,
		?string $syncToken,
	): bool
	{
		$eventResponse = $externalEventData->item;
		$familyId = $externalEventData->eventConnection->getRecurrenceId()
			?: $externalEventData->eventConnection->getVendorEventId();
		$forced = !empty($this->familyExcludedDates[$familyId]);
		foreach ($this->rawFamilyLinks[$familyId] ?? [] as $link)
		{
			$forced = $forced || ($link['DATA']['icloudImportPending'] ?? null) === true;
		}
		$forced = $forced || ($localEventData?->eventConnection->getData()['icloudImportPending'] ?? null) === true;
		if ($localEventData !== null && !$localEventData->getEvent()->canBeChanged())
		{
			if ($forced && !$this->canSkipPendingImmutableEvent($externalEventData, $localEventData, $familyId))
			{
				throw new ICloudImportIncompleteException('iCloud immutable event has unfinished dependencies.');
			}
			$this->domainSkipped = true;

			return false;
		}
		if ($forced)
		{
			return true;
		}

		if ($eventResponse->isEmpty() && !$externalEventData->isDeleted())
		{
			return false;
		}

		if (!$syncToken && $eventResponse->isOld())
		{
			return false;
		}

		if (!$localEventData)
		{
			return true;
		}

		if ($externalEventData->getEvent()->isInstance())
		{
			return true;
		}

		return $this->shouldEventBeSynchronized($externalEventData, $localEventData);
	}

	private function canSkipPendingImmutableEvent(
		ExternalEventData $externalData,
		EventData $localData,
		string $familyId,
	): bool
	{
		if ($externalData->isDeleted() || $externalData->getEvent()->isInstance()
			|| $localData->getEvent()->isInstance()
			|| $externalData->getInstances() || $localData->getInstances()
			|| !empty($this->familyExcludedDates[$familyId]))
		{
			return false;
		}
		foreach ($this->rawFamilyLinks[$familyId] ?? [] as $link)
		{
			if ($link['RECURRENCE_ID'])
			{
				return false;
			}
		}

		return true;
	}

	private function shouldEventBeSynchronized(ExternalEventData $externalEventData, EventData $localEventData): bool
	{
		$eventResponse = $externalEventData->item;

		$localEventConnection = $localEventData->eventConnection;

		if (
			$eventResponse->etag === $localEventConnection->getEntityTag()
			|| $eventResponse->getVersion() === $localEventConnection->getVendorVersionId()
		)
		{
			return false;
		}

		if ($eventResponse->etag !== $localEventConnection->getEntityTag())
		{
			return true;
		}

		if ($externalEventData->getEvent()->isRecurrence())
		{
			$externalInstances = $externalEventData->getInstances();

			foreach ($externalInstances as $externalInstance)
			{
				$localInstance = $localEventData->getSameInstance($externalInstance);

				if (!$localInstance)
				{
					return true;
				}

				$localInstanceConnection = $localInstance->eventConnection;

				if ($externalInstance->item->etag !== $localInstanceConnection->getEntityTag())
				{
					return true;
				}
			}
		}

		return false;
	}

	protected function shouldEnrichFromSameLocal(EventData $externalData): bool
	{
		$localEventData = $this->map->get($externalData->eventConnection->getVendorEventId());

		return
			$localEventData !== null
			&& $localEventData->getEvent()->getId() !== $externalData->getEvent()->getRecurrenceId()
		;
	}

	protected function enrichExternalData(EventData $externalData): void
	{
		parent::enrichExternalData($externalData);
		$event = $externalData->getEvent();
		if ($event->isInstance())
		{
			return;
		}
		$familyDates = $this->familyExcludedDates[$externalData->eventConnection->getVendorEventId()] ?? [];
		if (!$familyDates)
		{
			return;
		}

		$excludedDates = $event->getExcludedDateCollection() ?? new ExcludedDatesCollection();
		$excludedDays = [];
		foreach ($excludedDates as $date)
		{
			$excludedDays[$date->format('Ymd')] = true;
		}
		foreach ($familyDates as $date)
		{
			$day = $date->format('Ymd');
			if (!isset($excludedDays[$day]))
			{
				$excludedDates->add(clone $date);
				$excludedDays[$day] = true;
			}
		}
		$event->setExcludedDateCollection($excludedDates);
	}

	protected function onEventSaveResult(EventData $externalEvent, ?Event $savedEvent): void
	{
		foreach ([$externalEvent->getEvent()->getId(), $savedEvent?->getId()] as $eventId)
		{
			if ($eventId !== null)
			{
				$this->touchedEventIds[] = $eventId;
			}
		}
		if ($savedEvent === null)
		{
			throw new ICloudImportIncompleteException('iCloud event was not saved.');
		}
		$incomingEvent = $externalEvent->getEvent();
		if ($savedEvent !== $incomingEvent && !$incomingEvent->isInstance()
			&& !empty($this->familyExcludedDates[$externalEvent->eventConnection->getVendorEventId()]))
		{
			$savedEvent->setExcludedDateCollection($incomingEvent->getExcludedDateCollection());
		}
	}

	/**
	 * @throws BaseException
	 */
	protected function mergeEventData(
		EventData $externalEventData,
		EventData $localEventData,
		int $id = null,
		int $eventConnectionId = null,
	): void
	{
		$externalEventData->getEvent()->setAccessibility($localEventData->getEvent()->getAccessibility());

		parent::mergeEventData($externalEventData, $localEventData, $id, $eventConnectionId);

		if ($eventConnectionId !== null)
		{
			$localConnection = $localEventData->eventConnection;
			$data = $localConnection->getData() ?? [];
			unset($data['ATTENDEE'], $data['ATTACH'], $data['ORGANIZER'], $data['URL']);

			$externalEventData->eventConnection
				->setVendorEventId($localConnection->getVendorEventId())
				->setRecurrenceId($localConnection->getRecurrenceId())
				->setEntityTag($localConnection->getEntityTag())
				->setVendorVersionId($localConnection->getVendorVersionId())
				->setLastSyncStatus($localConnection->getLastSyncStatus())
				->setData(array_replace($data, $externalEventData->eventConnection->getData() ?? []))
			;
		}
	}

	protected function buildSavingParams(EventData $externalEvent): array
	{
		return [
			'joinEventSaveTransaction' => true,
			'userId' => (int)$externalEvent->getEvent()->getOwner()?->getId(),
			'bAffectToDav' => false, // Used to prevent synchronization with calDav again
			'bSilentAccessMeeting' => true,
			'autoDetectSection' => false,
			'originalFrom' => $externalEvent->eventConnection->getConnection()->getVendor()->getCode(),
		];
	}
}
