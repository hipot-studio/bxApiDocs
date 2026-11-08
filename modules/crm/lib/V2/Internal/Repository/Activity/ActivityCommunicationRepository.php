<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Repository\Activity;

use Bitrix\Crm\CompanyTable;
use Bitrix\Crm\ContactTable;
use Bitrix\Crm\DealTable;
use Bitrix\Crm\LeadTable;
use Bitrix\Crm\V2\Internal\Entity\Activity\ActivityCommunication;
use Bitrix\Crm\V2\Internal\Model\Activity\ActivityCommunicationTable;
use Bitrix\Crm\V2\Public\OwnerType;
use Bitrix\Main\Application;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Query\Filter\ConditionTree;

/**
 * The rows of `b_crm_act_comm` behind one activity: through which channel and at which address it talks, and
 * to which CRM entity that address belongs.
 *
 * THE SET IS REPLACED, NOT DIFFERENCED, and unlike the bindings that is not a decision to revisit here: the
 * ids of the rows are not stable in legacy and the registry of intentional deviations grants them no
 * stability, so the write deletes the whole set and inserts the given one back (F6.3).
 *
 * THREE STATES OF THE SET, as legacy has them (F6.4): no set at all means "leave what is stored alone", an
 * empty set means "remove everything", and a set means "let this be the set". The absence is `null` here,
 * which is also what {@see \Bitrix\Crm\V2\Internal\Entity\Activity\Activity::getCommunications()} answers
 * for a communication set nobody touched.
 *
 * TWO THINGS THE MODEL DOES NOT CARRY AND THIS CLASS FILLS IN. The owner of a communication is always the
 * owner of the activity, so it is read from the row of the activity rather than taken from a caller (F6.2).
 * And `ENTITY_SETTINGS` holds a serialized snapshot of the names of that entity, taken at write time exactly
 * as legacy takes it (`PrepareCommunicationSettings`, crm_activity.php:3480-3559), so that a reader of the
 * communication can show who it belongs to without joining the entity (F6.1). An entity that no longer
 * exists gets an empty snapshot, never a missing one, and so does an address that names no entity at all -
 * legacy answers both with the empty array of `PrepareCommunicationSettings` (:3922-3923).
 *
 * AN ADDRESS WITHOUT AN ENTITY IS A COMMUNICATION, on both ends of this boundary. Legacy stores the given set
 * element for element (mysql/crm_activity.php:317-332) and outgoing mail relies on it: it names the channel
 * and the address of the addressee always, the entity only where the caller handed an `ADDRESS_SOURCE` over
 * (integration/mailmanager.php:249-253, :292-298). Its row carries the entity columns at zero, and a reader
 * of it - the search index and the statistics of the channels among them - finds the row where legacy leaves
 * one.
 *
 * NO EVENT IS PUBLISHED. Legacy could write the CRM history of a communication from the write path, but not
 * from the pipelines - they pass the flag as false - so there is nothing to reproduce here (F6.5).
 *
 * @internal
 */
final class ActivityCommunicationRepository
{
	private readonly ActivityRepositoryInterface $activityRepository;

	public function __construct(?ActivityRepositoryInterface $activityRepository = null)
	{
		$this->activityRepository = $activityRepository ?? new ActivityRepository();
	}

	/**
	 * Communications of the activity, in the order of their rows.
	 *
	 * A ROW WITHOUT AN ENTITY IS ONE OF THEM, because it is one for legacy: `GetCommunications()` returns
	 * such a row with its zeros, and outgoing mail writes them (see the note of the class). A half-named
	 * pair is read as the absence of an entity, the rule legacy's own readers judge by
	 * (`PrepareCommunicationInfos`, crm_activity.php:4018-4022). The one row the model cannot describe is
	 * one without a channel: a communication without one names nothing at all, and no write path of the CRM
	 * produces it.
	 *
	 * @return ActivityCommunication[]
	 * @throws ArgumentException on a non-positive id.
	 */
	public function getByActivityId(int $activityId): array
	{
		$this->assertActivityId($activityId);

		$communications = [];
		$rows =
			ActivityCommunicationTable::query()
				->setSelect(['ID', 'TYPE', 'VALUE', 'ENTITY_ID', 'ENTITY_TYPE_ID'])
				->where('ACTIVITY_ID', $activityId)
				->setOrder(['ID' => 'ASC'])
				->fetchAll()
		;

		foreach ($rows as $row)
		{
			$communication = $this->communicationFromRow($row);
			if ($communication !== null)
			{
				$communications[] = $communication;
			}
		}

		return $communications;
	}

	/**
	 * Communications of the given activities in one query. Every set keeps the order of its rows and the
	 * repetitions among them, because the write stores the given set element for element and a single read
	 * hands it back the same way.
	 *
	 * NOTHING IS MASKED HERE. An activity can be readable while the client one of its communications names is
	 * not, but the decision depends on who is asking, which the storage neither knows nor may guess, and a
	 * repository narrowing the set would narrow it for the write paths reading the same rows back.
	 *
	 * An activity with no row is absent from the result.
	 *
	 * @param int[] $activityIds
	 * @return array<int, ActivityCommunication[]> by activity id, each set in the order of its rows
	 */
	public function getByActivityIds(array $activityIds): array
	{
		$ids = [];
		foreach ($activityIds as $activityId)
		{
			$activityId = (int)$activityId;
			if ($activityId > 0)
			{
				$ids[$activityId] = $activityId;
			}
		}

		if ($ids === [])
		{
			return [];
		}

		$rows =
			ActivityCommunicationTable::query()
				->setSelect(['ACTIVITY_ID', 'TYPE', 'VALUE', 'ENTITY_ID', 'ENTITY_TYPE_ID'])
				->whereIn('ACTIVITY_ID', array_values($ids))
				->setOrder(['ID' => 'ASC'])
				->fetchAll()
		;

		$communications = [];
		foreach ($rows as $row)
		{
			$communication = $this->communicationFromRow($row);
			if ($communication !== null)
			{
				$communications[(int)$row['ACTIVITY_ID']][] = $communication;
			}
		}

		return $communications;
	}

	/**
	 * The communication a row describes, or null for the one row the model cannot - one without a channel,
	 * which names nothing at all. The rules both reads judge a row by are stated at {@see self::getByActivityId()}.
	 *
	 * @param array<string, mixed> $row
	 */
	private function communicationFromRow(array $row): ?ActivityCommunication
	{
		$type = (string)($row['TYPE'] ?? '');
		if ($type === '')
		{
			return null;
		}

		$entityId = (int)($row['ENTITY_ID'] ?? 0);
		$entityTypeId = (int)($row['ENTITY_TYPE_ID'] ?? 0);
		if ($entityId <= 0 || $entityTypeId <= 0)
		{
			$entityId = 0;
			$entityTypeId = OwnerType::UNDEFINED;
		}

		return new ActivityCommunication(
			$type,
			(string)($row['VALUE'] ?? ''),
			OwnerType::fromId($entityTypeId),
			$entityId,
		);
	}

	/**
	 * Stores the given set as the set of the activity.
	 *
	 * @param ActivityCommunication[]|null $communications null leaves the stored set untouched.
	 * @throws ArgumentException on a non-positive id, or when the write fails.
	 */
	public function save(int $activityId, ?array $communications): void
	{
		$this->assertActivityId($activityId);

		if ($communications === null)
		{
			return;
		}

		$this->deleteByActivityId($activityId);

		if ($communications === [])
		{
			return;
		}

		$owner = $this->activityRepository->getOwners([$activityId])[$activityId] ?? null;
		$snapshots = $this->readEntityNameSnapshots($communications);

		$rows = [];
		foreach ($communications as $communication)
		{
			$entityTypeId = $communication->getEntityType()->getId();
			$entityId = $communication->getEntityId();

			$rows[] = [
				'ACTIVITY_ID' => $activityId,
				'OWNER_ID' => $owner?->getOwnerId() ?? 0,
				'OWNER_TYPE_ID' => $owner?->getOwnerType()->getId() ?? OwnerType::UNDEFINED,
				'TYPE' => $communication->getType(),
				'VALUE' => $communication->getValue(),
				'ENTITY_ID' => $entityId,
				'ENTITY_TYPE_ID' => $entityTypeId,
				'ENTITY_SETTINGS' => serialize($snapshots["{$entityTypeId}_{$entityId}"] ?? []),
			];
		}

		$result = ActivityCommunicationTable::addMulti($rows, true);
		if (!$result->isSuccess())
		{
			throw new ArgumentException(
				"Could not store the communications of activity #{$activityId}: "
				. implode('; ', $result->getErrorMessages()),
			);
		}
	}

	/**
	 * Removes every communication of the activity. The step of the pipeline that deletes an activity needs
	 * it on its own, before the row of the activity itself is gone.
	 *
	 * @throws ArgumentException on a non-positive id.
	 */
	public function deleteByActivityId(int $activityId): void
	{
		$this->assertActivityId($activityId);

		$connection = Application::getConnection();
		$helper = $connection->getSqlHelper();

		$connection->queryExecute(
			'DELETE FROM ' . $helper->quote(ActivityCommunicationTable::getTableName())
			. ' WHERE ' . $helper->quote('ACTIVITY_ID') . ' = ' . $activityId,
		);
	}

	//region the snapshot of the entity names

	/**
	 * The snapshot of every entity the given communications point at, by "{entityTypeId}_{entityId}".
	 *
	 * One query per type of entity instead of one per communication: the shape of each snapshot is the one
	 * legacy builds, and the entities of one type all need the same columns.
	 *
	 * @param ActivityCommunication[] $communications
	 * @return array<string, array<string, string>>
	 */
	private function readEntityNameSnapshots(array $communications): array
	{
		$idsByType = [];
		foreach ($communications as $communication)
		{
			$typeId = $communication->getEntityType()->getId();
			$idsByType[$typeId][$communication->getEntityId()] = $communication->getEntityId();
		}

		$snapshots = [];
		foreach ($idsByType as $typeId => $ids)
		{
			foreach ($this->readSnapshotsOfType($typeId, array_values($ids)) as $entityId => $snapshot)
			{
				$snapshots["{$typeId}_{$entityId}"] = $snapshot;
			}
		}

		return $snapshots;
	}

	/**
	 * Snapshots of the entities of one type, by entity id. A type legacy takes no snapshot of, and an id
	 * that has no row, are simply absent - the caller writes an empty snapshot for them.
	 *
	 * @param int[] $ids
	 * @return array<int, array<string, string>>
	 */
	private function readSnapshotsOfType(int $typeId, array $ids): array
	{
		return match ($typeId)
		{
			OwnerType::LEAD => array_map(
				static fn(array $row): array => [
					'HONORIFIC' => (string)($row['HONORIFIC'] ?? ''),
					'NAME' => (string)($row['NAME'] ?? ''),
					'SECOND_NAME' => (string)($row['SECOND_NAME'] ?? ''),
					'LAST_NAME' => (string)($row['LAST_NAME'] ?? ''),
					'LEAD_TITLE' => (string)($row['TITLE'] ?? ''),
				],
				$this->readRowsById(
					LeadTable::class,
					['ID', 'HONORIFIC', 'NAME', 'SECOND_NAME', 'LAST_NAME', 'TITLE'],
					$ids,
				),
			),
			OwnerType::DEAL => array_map(
				static fn(array $row): array => ['DEAL_TITLE' => (string)($row['TITLE'] ?? '')],
				$this->readRowsById(DealTable::class, ['ID', 'TITLE'], $ids),
			),
			OwnerType::COMPANY => array_map(
				static fn(array $row): array => ['COMPANY_TITLE' => (string)($row['TITLE'] ?? '')],
				$this->readRowsById(CompanyTable::class, ['ID', 'TITLE'], $ids),
			),
			OwnerType::CONTACT => $this->readContactSnapshots($ids),
			default => [],
		};
	}

	/**
	 * The snapshot of a contact carries the company it belongs to on top of its name, and only when that
	 * company exists.
	 *
	 * @param int[] $ids
	 * @return array<int, array<string, string>>
	 */
	private function readContactSnapshots(array $ids): array
	{
		$snapshots = [];
		$companyIdByContact = [];

		$rows = $this->readRowsById(
			ContactTable::class,
			['ID', 'HONORIFIC', 'NAME', 'SECOND_NAME', 'LAST_NAME', 'COMPANY_ID'],
			$ids,
		);

		foreach ($rows as $contactId => $row)
		{
			$snapshots[$contactId] = [
				'HONORIFIC' => (string)($row['HONORIFIC'] ?? ''),
				'NAME' => (string)($row['NAME'] ?? ''),
				'SECOND_NAME' => (string)($row['SECOND_NAME'] ?? ''),
				'LAST_NAME' => (string)($row['LAST_NAME'] ?? ''),
			];

			$companyId = (int)($row['COMPANY_ID'] ?? 0);
			if ($companyId > 0)
			{
				$companyIdByContact[$contactId] = $companyId;
			}
		}

		$companies = $this->readRowsById(
			CompanyTable::class,
			['ID', 'TITLE'],
			array_values(array_unique($companyIdByContact)),
		);

		foreach ($companyIdByContact as $contactId => $companyId)
		{
			if (!isset($companies[$companyId]))
			{
				continue;
			}

			$snapshots[$contactId]['COMPANY_TITLE'] = (string)($companies[$companyId]['TITLE'] ?? '');
			$snapshots[$contactId]['COMPANY_ID'] = (string)$companyId;
		}

		return $snapshots;
	}

	/**
	 * @param class-string<DataManager> $dataManager
	 * @param string[] $columns
	 * @param int[] $ids
	 * @return array<int, array> rows by their id
	 */
	private function readRowsById(string $dataManager, array $columns, array $ids): array
	{
		if ($ids === [])
		{
			return [];
		}

		$rowsById = [];
		$rows =
			$dataManager::query()
				->setSelect($columns)
				->where((new ConditionTree())->whereIn('ID', $ids))
				->fetchAll()
		;

		foreach ($rows as $row)
		{
			$rowsById[(int)$row['ID']] = $row;
		}

		return $rowsById;
	}

	//endregion

	/**
	 * @throws ArgumentException on a non-positive id.
	 */
	private function assertActivityId(int $activityId): void
	{
		if ($activityId <= 0)
		{
			throw new ArgumentException('Communications belong to an activity that has an id');
		}
	}
}
