<?php

namespace Bitrix\Crm;

use Bitrix\Crm\Service\EventHistory;
use Bitrix\Main\Application;
use Bitrix\Main\DB\SqlQueryException;
use Bitrix\Main\Error;
use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Data\Internal\DeleteByFilterTrait;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\ORM\Fields\Relations\Reference;
use Bitrix\Main\ORM\Fields\StringField;
use Bitrix\Main\ORM\Query\Filter\ConditionTree;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Main\Result;

/**
 * Class EventRelationsTable
 *
 * DO NOT WRITE ANYTHING BELOW THIS
 *
 * <<< ORMENTITYANNOTATION
 * @method static EO_EventRelations_Query query()
 * @method static EO_EventRelations_Result getByPrimary($primary, array $parameters = [])
 * @method static EO_EventRelations_Result getById($id)
 * @method static EO_EventRelations_Result getList(array $parameters = [])
 * @method static EO_EventRelations_Entity getEntity()
 * @method static \Bitrix\Crm\EO_EventRelations createObject($setDefaultValues = true)
 * @method static \Bitrix\Crm\EO_EventRelations_Collection createCollection()
 * @method static \Bitrix\Crm\EO_EventRelations wakeUpObject($row)
 * @method static \Bitrix\Crm\EO_EventRelations_Collection wakeUpCollection($rows)
 */
class EventRelationsTable extends DataManager
{
	use DeleteByFilterTrait;

	private const BATCH_SIZE = 1000;

	public static function getTableName(): string
	{
		return 'b_crm_event_relations';
	}

	public static function getMap(): array
	{
		return [
			(new IntegerField('ID'))
				->configurePrimary()
				->configureAutocomplete(),
			new IntegerField('ASSIGNED_BY_ID'),
			new StringField('ENTITY_TYPE'),
			new IntegerField('ENTITY_ID'),
			new StringField('ENTITY_FIELD'),
			new IntegerField('EVENT_ID'),
			new Reference(
				'EVENT_BY',
				EventTable::class,
				[
					'=this.EVENT_ID' => 'ref.ID',
				]
			),
		];
	}

	public static function deleteByEntityType(string $entityType): Result
	{
		return static::deleteRecords($entityType);
	}

	public static function deleteByItem(int $entityTypeId, int $id): Result
	{
		return static::deleteRecords(\CCrmOwnerType::ResolveName($entityTypeId), $id);
	}

	private static function deleteRecords(string $entityTypeName, ?int $id = null): Result
	{
		while (true)
		{
			$relations = self::getOwnedRelationsBatch($entityTypeName, $id);
			if ($relations === [])
			{
				return new Result();
			}

			$relationIds = self::getUniqueColumnValues($relations, 'ID');
			$eventIds = self::getUniqueColumnValues($relations, 'EVENT_ID');
			$linkEventIds = self::getLinkEventIds($eventIds);

			// relations of the batch go first: an event still referenced by them would not look orphaned yet
			$result = self::deleteRelationsByIds($relationIds);
			if (!$result->isSuccess())
			{
				return $result;
			}

			if ($linkEventIds !== [])
			{
				$result = self::deleteRelationsByEventIds($linkEventIds);
				if (!$result->isSuccess())
				{
					return $result;
				}
			}

			$orphanEvents = self::getOrphanEvents($eventIds);
			if ($orphanEvents === [])
			{
				continue;
			}

			$orphanEventIds = self::getUniqueColumnValues($orphanEvents, 'ID');
			$result = self::deleteEventsByIds($orphanEventIds);
			if (!$result->isSuccess())
			{
				return $result;
			}

			self::deleteFiles($orphanEvents);
		}
	}

	private static function getOwnedRelationsBatch(string $entityTypeName, ?int $id = null): array
	{
		// no order and no offset: sorting by ID is not covered by an index, and an offset over a set
		// that is being deleted from skips rows
		$query = self::query()
			->setSelect(['ID', 'EVENT_ID'])
			->where('ENTITY_TYPE', $entityTypeName)
			->setLimit(self::BATCH_SIZE)
		;
		if (!is_null($id))
		{
			$query->where('ENTITY_ID', $id);
		}

		$relations = [];
		$list = $query->exec();
		while ($row = $list->fetch())
		{
			$relations[] = [
				'ID' => (int)$row['ID'],
				'EVENT_ID' => (int)$row['EVENT_ID'],
			];
		}

		return $relations;
	}

	private static function getLinkEventIds(array $eventIds): array
	{
		if ($eventIds === [])
		{
			return [];
		}

		$linkEventIds = [];
		$list = EventTable::query()
			->setSelect(['ID'])
			->whereIn('ID', $eventIds)
			->whereIn('EVENT_TYPE', [EventHistory::EVENT_TYPE_LINK, EventHistory::EVENT_TYPE_UNLINK])
			->exec()
		;
		while ($row = $list->fetch())
		{
			$linkEventIds[] = (int)$row['ID'];
		}

		return array_values(array_unique($linkEventIds));
	}

	private static function deleteRelationsByIds(array $relationIds): Result
	{
		return self::deleteByCondition(Query::filter()->whereIn('ID', $relationIds));
	}

	private static function deleteRelationsByEventIds(array $eventIds): Result
	{
		return self::deleteByCondition(Query::filter()->whereIn('EVENT_ID', $eventIds));
	}

	private static function deleteEventsByIds(array $eventIds): Result
	{
		return self::deleteByCondition(
			Query::filter()->whereIn('ID', $eventIds),
			EventTable::class
		);
	}

	/**
	 * @param class-string<DataManager> $dataClass
	 */
	private static function deleteByCondition(
		array|ConditionTree $filter,
		string $dataClass = self::class
	): Result
	{
		$result = new Result();

		try
		{
			$dataClass::deleteByFilter($filter);
		}
		catch (SqlQueryException $exception)
		{
			Application::getInstance()->getExceptionHandler()->writeToLog($exception);

			$result->addError(new Error('Failed to delete a batch of CRM event history records.'));
		}

		return $result;
	}

	private static function getOrphanEvents(array $eventIds): array
	{
		if ($eventIds === [])
		{
			return [];
		}

		$events = [];
		// a null join means no relation rows are left, so the event is unreachable and can be dropped
		$list =
			EventTable::query()
				->setSelect(['ID', 'FILES'])
				->whereIn('ID', $eventIds)
				->whereNull('EVENT_RELATION.EVENT_ID')
				->exec()
		;
		while ($row = $list->fetch())
		{
			$events[] = [
				'ID' => (int)$row['ID'],
				'FILES' => $row['FILES'],
			];
		}

		return $events;
	}

	private static function deleteFiles(array $events): void
	{
		foreach ($events as $event)
		{
			$serializedFileIds = $event['FILES'] ?? null;
			if (!is_string($serializedFileIds) || $serializedFileIds === '')
			{
				continue;
			}

			$fileIds = unserialize($serializedFileIds, ['allowed_classes' => false]);
			if (!is_array($fileIds))
			{
				continue;
			}

			foreach ($fileIds as $fileId)
			{
				\CFile::Delete((int)$fileId);
			}
		}
	}

	private static function getUniqueColumnValues(array $rows, string $columnName): array
	{
		$values = [];
		foreach ($rows as $row)
		{
			$value = (int)($row[$columnName] ?? 0);
			if ($value > 0)
			{
				$values[$value] = $value;
			}
		}

		return array_values($values);
	}
}
