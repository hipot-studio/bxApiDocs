<?php

declare(strict_types=1);

namespace Bitrix\Calendar\Synchronization\Internal\Repository;

use Bitrix\Calendar\Internals\EventTable;
use Bitrix\Calendar\Sync\Dictionary;
use Bitrix\Calendar\Synchronization\Internal\Entity\EventConnection;
use Bitrix\Calendar\Synchronization\Internal\Entity\SectionConnection;
use Bitrix\Calendar\Synchronization\Internal\Exception\Repository\RepositoryReadException;
use Bitrix\Calendar\Synchronization\Internal\Model\EventConnectionTable;
use Bitrix\Calendar\Synchronization\Internal\Repository\Mapper\EventConnectionMapper;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\Entity\EntityCollection;
use Bitrix\Main\Entity\EntityInterface;
use Bitrix\Main\ObjectPropertyException;
use Bitrix\Main\ORM\Query\Filter\ConditionTree;
use Bitrix\Main\Repository\Exception\PersistenceException;
use Bitrix\Main\Repository\RepositoryInterface;
use Bitrix\Main\SystemException;
use Exception;

readonly class EventConnectionRepository implements RepositoryInterface
{
	private const IMPORT_LINK_CHUNK_SIZE = 500;
	private \ArrayObject $importLinkAcknowledgements;

	public function __construct(private EventConnectionMapper $mapper)
	{
		$this->importLinkAcknowledgements = new \ArrayObject();
	}

	public function getRawLinksByVendorIds(array $ids, int $connectionId): array
	{
		$links = [];
		foreach (array_chunk(array_values(array_unique($ids)), self::IMPORT_LINK_CHUNK_SIZE) as $chunk)
		{
			$result = EventConnectionTable::query()
				->setSelect([
					'ID', 'EVENT_ID', 'CONNECTION_ID', 'VENDOR_EVENT_ID', 'RECURRENCE_ID',
					'ENTITY_TAG', 'VENDOR_VERSION_ID', 'DATA',
				])
				->where('CONNECTION_ID', $connectionId)
				->where(
					(new ConditionTree())
						->logic(ConditionTree::LOGIC_OR)
						->whereIn('VENDOR_EVENT_ID', $chunk)
						->whereIn('RECURRENCE_ID', $chunk),
				)
				->exec()
			;
			while ($row = $result->fetch())
			{
				$links[(int)$row['ID']] = $row;
			}
		}

		return array_values($links);
	}

	public function hasAnyRawLinksByVendorIds(array $ids, int $connectionId): bool
	{
		foreach (array_chunk(array_values(array_unique($ids)), self::IMPORT_LINK_CHUNK_SIZE) as $chunk)
		{
			$result = EventConnectionTable::query()
				->setSelect(['ID'])
				->where('CONNECTION_ID', $connectionId)
				->where(
					(new ConditionTree())
						->logic(ConditionTree::LOGIC_OR)
						->whereIn('VENDOR_EVENT_ID', $chunk)
						->whereIn('RECURRENCE_ID', $chunk),
				)
				->setLimit(1)
				->exec()
			;
			if (is_array($result->fetch()))
			{
				return true;
			}
		}

		return false;
	}

	public function getRawImportFamilyRows(string $familyId, int $connectionId): array
	{
		$result = EventConnectionTable::query()
			->setSelect([
				'ID', 'EVENT_ID', 'CONNECTION_ID', 'VENDOR_EVENT_ID', 'RECURRENCE_ID',
				'ENTITY_TAG', 'VENDOR_VERSION_ID', 'DATA',
				'EVENT_ROW_ID' => 'EVENT.ID',
				'EVENT_DELETED' => 'EVENT.DELETED',
				'EVENT_SECTION_ID' => 'EVENT.SECTION_ID',
				'EVENT_PARENT_ID' => 'EVENT.PARENT_ID',
				'EVENT_RECURRENCE_ID' => 'EVENT.RECURRENCE_ID',
				'EVENT_ORIGINAL_DATE_FROM' => 'EVENT.ORIGINAL_DATE_FROM',
				'EVENT_TZ_FROM' => 'EVENT.TZ_FROM',
				'EVENT_DT_SKIP_TIME' => 'EVENT.DT_SKIP_TIME',
			])
			->where('CONNECTION_ID', $connectionId)
			->where(
				(new ConditionTree())
					->logic(ConditionTree::LOGIC_OR)
					->where('VENDOR_EVENT_ID', $familyId)
					->where('RECURRENCE_ID', $familyId),
			)
			->exec()
		;
		$rows = [];
		while ($row = $result->fetch())
		{
			$rows[] = $row;
		}

		return $rows;
	}

	public function getRawEventRowsByIds(array $ids): array
	{
		$events = [];
		foreach (array_chunk(array_values(array_unique($ids)), self::IMPORT_LINK_CHUNK_SIZE) as $chunk)
		{
			$result = EventTable::query()
				->setSelect([
					'ID', 'DELETED', 'SECTION_ID', 'PARENT_ID', 'RECURRENCE_ID',
					'ORIGINAL_DATE_FROM', 'TZ_FROM', 'DT_SKIP_TIME',
				])
				->whereIn('ID', $chunk)
				->exec()
			;
			while ($row = $result->fetch())
			{
				$events[(int)$row['ID']] = $row;
			}
		}

		return $events;
	}

	public function getCurrentImportFamilyRows(string $familyId, int $connectionId): array
	{
		return $this->getCurrentImportFamiliesRows([$familyId], $connectionId);
	}

	public function getCurrentImportFamiliesRows(
		array $familyIds,
		int $connectionId,
		?int $sectionId = null,
	): array
	{
		$familyIds = array_values(array_unique($familyIds));
		if (!$familyIds)
		{
			return [];
		}
		if (count($familyIds) > self::IMPORT_LINK_CHUNK_SIZE)
		{
			throw new ArgumentException('Import completion family batch exceeds its limit.');
		}

		return $this->getCurrentImportFamiliesRowsWithLimit(
			$familyIds, $connectionId, $sectionId, self::IMPORT_LINK_CHUNK_SIZE + 1,
		);
	}

	public function getCurrentImportFamilyCompletionRows(
		string $familyId,
		int $connectionId,
		int $sectionId,
		int $expectedLinkCount,
	): array
	{
		return $this->getCurrentImportFamiliesRowsWithLimit(
			[$familyId], $connectionId, $sectionId, $expectedLinkCount + 1,
		);
	}

	private function getCurrentImportFamiliesRowsWithLimit(
		array $familyIds,
		int $connectionId,
		?int $sectionId,
		int $rowLimit,
	): array
	{
		$query = EventConnectionTable::query()
			->setSelect([
				'ID', 'EVENT_ID', 'CONNECTION_ID', 'VENDOR_EVENT_ID', 'RECURRENCE_ID',
				'ENTITY_TAG', 'VENDOR_VERSION_ID', 'SYNC_STATUS', 'DATA',
				'EVENT_ROW_ID' => 'EVENT.ID',
				'EVENT_DELETED' => 'EVENT.DELETED',
				'EVENT_SECTION_ID' => 'EVENT.SECTION_ID',
				'EVENT_PARENT_ID' => 'EVENT.PARENT_ID',
				'EVENT_RECURRENCE_ID' => 'EVENT.RECURRENCE_ID',
				'EVENT_ORIGINAL_DATE_FROM' => 'EVENT.ORIGINAL_DATE_FROM',
				'EVENT_TZ_FROM' => 'EVENT.TZ_FROM',
				'EVENT_DT_SKIP_TIME' => 'EVENT.DT_SKIP_TIME',
				'EVENT_SPECIAL_LABEL' => 'EVENT.EVENT_TYPE',
			])
			->where('CONNECTION_ID', $connectionId)
			->where(
				(new ConditionTree())
					->logic(ConditionTree::LOGIC_OR)
					->whereIn('VENDOR_EVENT_ID', $familyIds)
					->whereIn('RECURRENCE_ID', $familyIds),
			)
			->setLimit($rowLimit)
		;
		if ($sectionId !== null && $sectionId > 0)
		{
			$query->where(
				(new ConditionTree())
					->logic(ConditionTree::LOGIC_OR)
					->whereNull('EVENT.ID')
					->where('EVENT_ID', '<=', 0)
					->whereColumn('EVENT.ID', '!=', 'EVENT_ID')
					->whereExpr("ASCII(COALESCE(NULLIF(%s, ''), '.')) <> 78", ['EVENT.DELETED'])
					->whereNull('EVENT.SECTION_ID')
					->where('EVENT.SECTION_ID', '<=', 0)
					->where('EVENT.SECTION_ID', $sectionId),
			);
		}
		$result = EventConnectionTable::getEntity()->getConnection()->query($query->getQuery());
		$rows = [];
		while ($row = $result->fetch())
		{
			$rows[] = $row;
		}

		return $rows;
	}

	/**
	 * Call inside the owned pending-identity or final acknowledgement transaction.
	 * The caller owns the row locks and must finish that transaction before preparing another family.
	 */
	public function prepareImportLinkAcknowledgements(
		array $linkIds,
		int $connectionId,
		string $familyId,
		?array $pendingOwnerIds = null,
	): void
	{
		$this->importLinkAcknowledgements->exchangeArray([]);
		$linkIds = array_values(array_unique(array_map('intval', $linkIds)));
		sort($linkIds, SORT_NUMERIC);
		$links = [];
		$pendingOwners = $pendingOwnerIds !== null
			? array_fill_keys(array_map('intval', $pendingOwnerIds), true)
			: null;
		$entity = EventConnectionTable::getEntity();
		$dataField = $entity->getField('DATA');
		foreach (array_chunk($linkIds, self::IMPORT_LINK_CHUNK_SIZE) as $chunk)
		{
			$query = EventConnectionTable::query()
				->setSelect([
					'ID', 'CONNECTION_ID', 'VENDOR_EVENT_ID', 'RECURRENCE_ID', 'DATA',
					'ENTITY_TAG', 'VENDOR_VERSION_ID', 'SYNC_STATUS',
				])
				->whereIn('ID', $chunk)
				->where('CONNECTION_ID', $connectionId)
				->where(
					(new ConditionTree())
						->logic(ConditionTree::LOGIC_OR)
						->where('VENDOR_EVENT_ID', $familyId)
						->where('RECURRENCE_ID', $familyId),
				)
				->setOrder(['ID' => 'ASC'])
			;
			$result = $entity->getConnection()->query($query->getQuery() . ' FOR UPDATE');
			while ($row = $result->fetch())
			{
				if ($row['VENDOR_EVENT_ID'] !== $familyId && $row['RECURRENCE_ID'] !== $familyId)
				{
					throw new PersistenceException('Import link acknowledgement family identity does not match.');
				}

				$data = $dataField->decode($row['DATA']);
				if (!is_array($data))
				{
					throw new PersistenceException('Import link acknowledgement DATA is not an array.');
				}

				if ($pendingOwners !== null && !isset($pendingOwners[(int)$row['ID']])
					&& ($data['icloudImportPending'] ?? null) === true)
				{
					throw new PersistenceException('Import family contains another unfinished href.');
				}

				$links[(int)$row['ID']] = [
					'connectionId' => $connectionId,
					'familyId' => $familyId,
					'etag' => $row['ENTITY_TAG'],
					'version' => $row['VENDOR_VERSION_ID'],
					'syncStatus' => $row['SYNC_STATUS'],
					'rawData' => $row['DATA'],
					'data' => $data,
				];
			}
		}
		if (count($links) !== count($linkIds))
		{
			throw new PersistenceException('Import link acknowledgement identity does not match.');
		}

		$this->importLinkAcknowledgements->exchangeArray($links);
	}

	public function markPreparedImportLinkPending(int $linkId, int $connectionId, string $familyId): void
	{
		$link = $this->importLinkAcknowledgements[$linkId] ?? null;
		try
		{
			if ($link === null || $link['connectionId'] !== $connectionId || $link['familyId'] !== $familyId)
			{
				throw new PersistenceException('Import pending marker requires its prepared family identity.');
			}
			$result = EventConnectionTable::update($linkId, [
				'DATA' => array_replace($link['data'], ['icloudImportPending' => true]),
			]);
			if (!$result->isSuccess())
			{
				throw new PersistenceException('Unable to mark import link pending.', null, $result->getErrors());
			}
		}
		finally
		{
			$this->importLinkAcknowledgements->exchangeArray([]);
		}
	}

	/**
	 * Uses the fresh, locked family snapshot prepared in the caller's current acknowledgement transaction.
	 */
	public function acknowledgeImportLink(
		int $linkId,
		int $connectionId,
		string $familyId,
		?string $etag,
		?string $version,
		bool $preserveVersions = false,
	): void
	{
		$link = $this->importLinkAcknowledgements[$linkId] ?? null;
		if (
			$link === null
			|| $link['connectionId'] !== $connectionId
			|| $link['familyId'] !== $familyId
		)
		{
			$this->importLinkAcknowledgements->exchangeArray([]);

			throw new PersistenceException('Import link acknowledgement requires its prepared family identity.');
		}

		$data = $link['data'];
		unset($data['icloudImportPending']);
		try
		{
			$fields = [
				'ENTITY_TAG' => $preserveVersions ? $link['etag'] : $etag,
				'VENDOR_VERSION_ID' => $preserveVersions ? $link['version'] : $version,
				'SYNC_STATUS' => Dictionary::SYNC_STATUS['success'],
				'DATA' => $data,
			];
			$dataField = EventConnectionTable::getEntity()->getField('DATA');
			if (
				$link['etag'] === $fields['ENTITY_TAG']
				&& $link['version'] === $fields['VENDOR_VERSION_ID']
				&& $link['syncStatus'] === $fields['SYNC_STATUS']
				&& $link['rawData'] === $dataField->encode($data)
			)
			{
				unset($this->importLinkAcknowledgements[$linkId]);

				return;
			}

			$result = EventConnectionTable::update($linkId, $fields);
			if (!$result->isSuccess())
			{
				throw new PersistenceException('Unable to acknowledge import link.', null, $result->getErrors());
			}
		}
		catch (Exception $exception)
		{
			$this->importLinkAcknowledgements->exchangeArray([]);

			throw $exception;
		}
		unset($this->importLinkAcknowledgements[$linkId]);
	}

	/**
	 * @param int $id
	 *
	 * @return EntityInterface|null
	 *
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	public function getById(mixed $id): ?EntityInterface
	{
		$ormModel = EventConnectionTable::getById($id)->fetchObject();

		if (!$ormModel)
		{
			return null;
		}

		return $this->mapper->convertFromOrm($ormModel);
	}

	/**
	 * @throws RepositoryReadException
	 */
	public function getOneByEventAndConnectionId(int $eventId, int $connectionId): ?EventConnection
	{
		try
		{
			$ormModel = EventConnectionTable::query()
				->setSelect(['*', 'EVENT', 'CONNECTION'])
				->where('EVENT_ID', '=', $eventId)
				->where('CONNECTION_ID', '=', $connectionId)
				->whereNotNull('EVENT.ID')
				->setLimit(1)
				->exec()
				->fetchObject()
			;
		}
		catch (SystemException $e)
		{
			throw new RepositoryReadException($e->getMessage(), $e->getCode(), $e);
		}

		if (!$ormModel)
		{
			return null;
		}

		return $this->mapper->convertFromOrm($ormModel);
	}

	/**
	 * @param int[] $ids
	 * @param SectionConnection $sectionConnection
	 *
	 * @return EntityCollection
	 *
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	public function getByIdsAndSectionConnection(array $ids, SectionConnection $sectionConnection): EntityCollection
	{
		$collection = new EntityCollection();

		if (empty($ids))
		{
			return $collection;
		}

		$query =
			EventConnectionTable::query()
				->setSelect(['*', 'EVENT', 'CONNECTION'])
				->where('CONNECTION_ID', '=', (int)$sectionConnection->getConnection()?->getId())
				->where('EVENT.SECTION_ID', '=', (int)$sectionConnection->getSection()?->getId())
				->whereNotNull('EVENT.ID')
				->where(
					(new ConditionTree())
						->logic(ConditionTree::LOGIC_OR)
						->whereIn('VENDOR_EVENT_ID', $ids)
						->whereIn('RECURRENCE_ID', $ids),
				)
		;

		$queryResult = $query->exec();

		while ($ormModel = $queryResult->fetchObject())
		{
			$collection->add($this->mapper->convertFromOrm($ormModel));
		}

		return $collection;
	}

	/**
	 * @param string $recurrenceId
	 * @param int $connectionId
	 *
	 * @return EntityCollection
	 *
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	public function getByRecurrenceAndConnectionId(string $recurrenceId, int $connectionId): EntityCollection
	{
		$collection = new EntityCollection();

		$query =
			EventConnectionTable::query()
				->setSelect(['*', 'EVENT', 'CONNECTION'])
				->where('RECURRENCE_ID', '=', $recurrenceId)
				->where('CONNECTION_ID', '=', $connectionId)
				->whereNotNull('EVENT.ID')
		;

		$queryResult = $query->exec();

		while ($ormModel = $queryResult->fetchObject())
		{
			$collection->add($this->mapper->convertFromOrm($ormModel));
		}

		return $collection;
	}

	/**
	 * @param int $eventId
	 * @param array $services
	 *
	 * @return EntityCollection
	 *
	 * @return EntityCollection
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	public function getActiveByEventAndServices(int $eventId, array $services): EntityCollection
	{
		$query = EventConnectionTable::query()
			->setSelect(['*', 'CONNECTION'])
			->where('EVENT_ID', '=', $eventId)
			->where('CONNECTION.IS_DELETED', '=', 'N')
			->whereIn('CONNECTION.ACCOUNT_TYPE', $services)
		;

		$queryResult = $query->exec();

		$collection = new EntityCollection();

		while ($ormModel = $queryResult->fetchObject())
		{
			$collection->add($this->mapper->convertFromOrm($ormModel));
		}

		return $collection;
	}

	/**
	 * @param EventConnection $entity
	 *
	 * @throws PersistenceException
	 */
	public function save(EntityInterface $entity): void
	{
		try
		{
			if ($entity->getId())
			{
				$result = EventConnectionTable::update($entity->getId(), $entity->toArray());
			}
			else
			{
				$result = EventConnectionTable::add($entity->toArray());
			}
		}
		catch (Exception $e)
		{
			throw new PersistenceException($e->getMessage(), $e);
		}

		if ($result->isSuccess() && !$entity->getId())
		{
			$entity->setId($result->getId());
		}

		if (!$result->isSuccess())
		{
			throw new PersistenceException('Unable to save event connection', null, $result->getErrors());
		}
	}

	/**
	 * @param EventConnection[] $eventConnections
	 *
	 * @throws ArgumentException
	 * @throws SystemException
	 */
	public function saveAll(array $eventConnections): void
	{
		if (empty($eventConnections))
		{
			return;
		}

		$ormCollection = EventConnectionTable::createCollection();

		foreach ($eventConnections as $eventConnection)
		{
			$ormEventConnection = $this->mapper->convertToOrm($eventConnection);
			$ormCollection->add($ormEventConnection);
		}

		$ormCollection->save(true);
	}

	/**
	 * @throws PersistenceException
	 */
	public function deleteBrokenEventConnections(int $sectionId, int $connectionId): void
	{
		try
		{
			EventConnectionTable::getEntity()->getConnection()->queryExecute(
				"DELETE ec FROM b_calendar_event_connection ec
					INNER JOIN b_calendar_event AS event ON event.ID=ec.EVENT_ID
				WHERE event.SECTION_ID = '$sectionId' AND ec.CONNECTION_ID = '$connectionId'"
			);
		}
		catch (SystemException $e)
		{
			throw new PersistenceException(
				sprintf(
					'Unable to delete event connections for section "%s" and connection "%s"',
					$sectionId,
					$connectionId
				),
				$e
			);
		}
	}

	/**
	 * @param int $id
	 *
	 * @throws PersistenceException
	 */
	public function delete(mixed $id): void
	{
		try
		{
			$result = EventConnectionTable::delete($id);
		}
		catch (Exception $e)
		{
			throw new PersistenceException($e->getMessage(), $e);
		}

		if (!$result->isSuccess())
		{
			throw new PersistenceException('Unable to delete event connection', null, $result->getErrors());
		}
	}

	/**
	 * @throws PersistenceException
	 */
	public function deleteBySectionAndConnection(int $sectionId, int $connectionId): void
	{
		try
		{
			// The method deleteByFilter works only in one table context (without joins)
			$queryResult = EventConnectionTable::query()
				->setSelect(['ID'])
				->where('CONNECTION_ID', $connectionId)
				->where('EVENT.SECTION_ID', $sectionId)
				->exec()
			;

			$ids = [];

			while ($ormModel = $queryResult->fetch())
			{
				$ids[] = (int)$ormModel['ID'];
			}

			if (empty($ids))
			{
				return;
			}

			$filter = new ConditionTree();

			$filter->whereIn('ID', $ids);

			EventConnectionTable::deleteByFilter($filter);
		}
		catch (Exception $e)
		{
			throw new PersistenceException($e->getMessage(), previous: $e);
		}
	}

	/**
	 * @throws PersistenceException
	 */
	public function deleteByVendorId(string $vendorId): void
	{
		try
		{
			$queryResult = EventConnectionTable::query()
				->setSelect(['ID'])
				->where('VENDOR_EVENT_ID', $vendorId)
				->exec()
			;

			while ($ormModel = $queryResult->fetchObject())
			{
				$id = $ormModel->getId();

				$result = EventConnectionTable::delete($id);

				if (!$result->isSuccess())
				{
					throw new PersistenceException(
						sprintf('Unable to delete event connection "%s"', $id),
						errors: $result->getErrors()
					);
				}
			}
		}
		catch (Exception $e)
		{
			throw new PersistenceException($e->getMessage(), $e);
		}
	}
}
