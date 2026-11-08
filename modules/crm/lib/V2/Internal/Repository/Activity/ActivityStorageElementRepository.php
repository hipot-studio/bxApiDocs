<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Repository\Activity;

use Bitrix\Crm\ActivityElementTable;
use Bitrix\Crm\ActivityTable;
use Bitrix\Crm\Integration\StorageType;
use Bitrix\Main\Application;
use Bitrix\Main\ArgumentException;

/**
 * The files attached to an activity, which the storage keeps in two places at once: the rows of
 * `b_crm_act_elem` and the serialized list in `STORAGE_ELEMENT_IDS` of the activity itself. Both are written
 * here, because a set that lives in one of them and not in the other is a set nobody can read back - the one
 * exception being the storage legacy refuses to write rows for, which {@see self::save()} explains.
 *
 * IDS AND NOT FILES. What both places hold is the identifiers of the elements inside the storage the activity
 * uses - the storage itself is one per activity and travels as a column - so that is what this repository
 * speaks, and it is also the form the change set of the pipeline compares.
 *
 * NO FILE IS TOUCHED. Removing a row of `b_crm_act_elem` does not remove the file it points at: whether a
 * file may be deleted at all depends on the storage and on who else attached it, which is the business of the
 * step of the pipeline that talks to the storage, not of the rows.
 *
 * @internal
 */
final class ActivityStorageElementRepository
{
	/**
	 * Element ids attached to the activity, as the rows of `b_crm_act_elem` have them.
	 *
	 * @return int[]
	 * @throws ArgumentException on a non-positive id.
	 */
	public function getElementIds(int $activityId): array
	{
		$this->assertActivityId($activityId);

		$elementIds = [];
		$rows =
			ActivityElementTable::query()
				->setSelect(['ELEMENT_ID'])
				->where('ACTIVITY_ID', $activityId)
				->setOrder(['ELEMENT_ID' => 'ASC'])
				->fetchAll()
		;

		foreach ($rows as $row)
		{
			$elementIds[] = (int)$row['ELEMENT_ID'];
		}

		return $elementIds;
	}

	/**
	 * Element ids of the given activities in one query. An activity with no row is absent from the result.
	 *
	 * THE ROWS ARE THE ANSWER AND THE COLUMN IS NOT. An activity moved to the recycle bin keeps its
	 * serialized `STORAGE_ELEMENT_IDS` so that a restoration can put the rows back, so a fallback to the
	 * column would answer for activities whose rows were deliberately taken away.
	 *
	 * @param int[] $activityIds
	 * @return array<int, int[]> by activity id, each set in the order of its ids
	 */
	public function getElementIdsByActivityIds(array $activityIds): array
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
			ActivityElementTable::query()
				->setSelect(['ACTIVITY_ID', 'ELEMENT_ID'])
				->whereIn('ACTIVITY_ID', array_values($ids))
				->setOrder(['ACTIVITY_ID' => 'ASC', 'ELEMENT_ID' => 'ASC'])
				->fetchAll()
		;

		$elementIds = [];
		foreach ($rows as $row)
		{
			$elementIds[(int)$row['ACTIVITY_ID']][] = (int)$row['ELEMENT_ID'];
		}

		return $elementIds;
	}

	/**
	 * Stores the given ids as the set of the activity, in the rows and in the column alike.
	 *
	 * @param int $storageTypeId the storage the rows name; one that is none of the known ones leaves them
	 *        alone, as legacy does - see the note inside.
	 * @param int[]|null $elementIds null leaves both the rows and the column untouched, as legacy does for
	 *        a write that says nothing about the files; an empty set removes everything.
	 * @throws ArgumentException on a non-positive id, or when a write fails.
	 */
	public function save(int $activityId, int $storageTypeId, ?array $elementIds): void
	{
		$this->assertActivityId($activityId);

		if ($elementIds === null)
		{
			return;
		}

		$elementIds = array_values(array_unique(array_map('intval', $elementIds)));

		// A storage that is none of the known ones refuses the rows whole: legacy leaves them as they were and
		// writes not a single one (`DoSaveElementIDs`, crm/classes/mysql/crm_activity.php:402-407), because a
		// row naming a storage nobody can read a file from is worse than no row. The column is not part of that
		// refusal - legacy writes it with the row of the activity itself (crm_activity.php:126, :537) - so the
		// two places really do part ways here, and they part the way legacy parts them.
		$hasKnownStorage = StorageType::isDefined($storageTypeId);
		if ($hasKnownStorage)
		{
			$this->deleteRows($activityId);
		}

		$this->writeColumn($activityId, $elementIds);

		if (!$hasKnownStorage || $elementIds === [])
		{
			return;
		}

		$rows = [];
		foreach ($elementIds as $elementId)
		{
			$rows[] = [
				'ACTIVITY_ID' => $activityId,
				'STORAGE_TYPE_ID' => $storageTypeId,
				'ELEMENT_ID' => $elementId,
			];
		}

		$result = ActivityElementTable::addMulti($rows, true);
		if (!$result->isSuccess())
		{
			throw new ArgumentException(
				"Could not store the storage elements of activity #{$activityId}: "
				. implode('; ', $result->getErrorMessages()),
			);
		}
	}

	/**
	 * Removes the rows of the activity and leaves the column as it is - which is what the delete path needs:
	 * moving an activity to the recycle bin clears the rows while the activity itself, and the list of the
	 * files it had, stay for a possible restoration.
	 *
	 * @throws ArgumentException on a non-positive id.
	 */
	public function deleteByActivityId(int $activityId): void
	{
		$this->assertActivityId($activityId);

		$this->deleteRows($activityId);
	}

	private function deleteRows(int $activityId): void
	{
		$connection = Application::getConnection();
		$helper = $connection->getSqlHelper();

		$connection->queryExecute(
			'DELETE FROM ' . $helper->quote(ActivityElementTable::getTableName())
			. ' WHERE ' . $helper->quote('ACTIVITY_ID') . ' = ' . $activityId,
		);
	}

	/**
	 * @param int[] $elementIds
	 * @throws ArgumentException when the write fails.
	 */
	private function writeColumn(int $activityId, array $elementIds): void
	{
		$result = ActivityTable::update($activityId, ['STORAGE_ELEMENT_IDS' => serialize($elementIds)]);
		if (!$result->isSuccess())
		{
			throw new ArgumentException(
				"Could not store the storage element ids of activity #{$activityId}: "
				. implode('; ', $result->getErrorMessages()),
			);
		}
	}

	/**
	 * @throws ArgumentException on a non-positive id.
	 */
	private function assertActivityId(int $activityId): void
	{
		if ($activityId <= 0)
		{
			throw new ArgumentException('Storage elements belong to an activity that has an id');
		}
	}
}
