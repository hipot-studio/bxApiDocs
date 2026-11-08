<?php
namespace Bitrix\Crm\Recycling\Entity;

use Bitrix\Main;

/**
 * Class RelationTable
 *
 * DO NOT WRITE ANYTHING BELOW THIS
 *
 * <<< ORMENTITYANNOTATION
 * @method static EO_Relation_Query query()
 * @method static EO_Relation_Result getByPrimary($primary, array $parameters = [])
 * @method static EO_Relation_Result getById($id)
 * @method static EO_Relation_Result getList(array $parameters = [])
 * @method static EO_Relation_Entity getEntity()
 * @method static \Bitrix\Crm\Recycling\Entity\EO_Relation createObject($setDefaultValues = true)
 * @method static \Bitrix\Crm\Recycling\Entity\EO_Relation_Collection createCollection()
 * @method static \Bitrix\Crm\Recycling\Entity\EO_Relation wakeUpObject($row)
 * @method static \Bitrix\Crm\Recycling\Entity\EO_Relation_Collection wakeUpCollection($rows)
 */
class RelationTable extends Main\Entity\DataManager
{
	private const PRIMARY_COLUMNS = [
		'SRC_ENTITY_TYPE_ID',
		'SRC_ENTITY_ID',
		'SRC_RECYCLE_BIN_ID',
		'DST_ENTITY_TYPE_ID',
		'DST_ENTITY_ID',
		'DST_RECYCLE_BIN_ID',
	];
	private const MAX_SET_RECYCLE_BIN_ATTEMPTS = 3;

	/**
	 * @inheritdoc
	 */
	public static function getTableName()
	{
		return 'b_crm_recycling_relation';
	}

	/**
	 * @inheritdoc
	 */
	public static function getMap()
	{
		return [
			new Main\Entity\IntegerField('SRC_ENTITY_TYPE_ID', [ 'primary' => true ]),
			new Main\Entity\IntegerField('SRC_ENTITY_ID', [ 'primary' => true ]),
			new Main\Entity\IntegerField('SRC_RECYCLE_BIN_ID'),
			new Main\Entity\IntegerField('DST_ENTITY_TYPE_ID', [ 'primary' => true ]),
			new Main\Entity\IntegerField('DST_ENTITY_ID', [ 'primary' => true ]),
			new Main\Entity\IntegerField('DST_RECYCLE_BIN_ID'),
			new Main\Entity\IntegerField('PREVIOUS_SRC_ENTITY_ID'),
			new Main\Entity\IntegerField('PREVIOUS_DST_ENTITY_ID'),
			new Main\Entity\DatetimeField('CREATED_TIME'),
			new Main\Entity\DatetimeField('LAST_UPDATED_TIME')
		];
	}

	public static function upsert(array $data)
	{
		$srcEntityTypeID = isset($data['SRC_ENTITY_TYPE_ID']) ? (int)$data['SRC_ENTITY_TYPE_ID'] : \CCrmOwnerType::Undefined;
		$srcEntityID = isset($data['SRC_ENTITY_ID']) ? (int)$data['SRC_ENTITY_ID'] : 0;
		$srcRecycleBinID = isset($data['SRC_RECYCLE_BIN_ID']) ? (int)$data['SRC_RECYCLE_BIN_ID'] : null;

		$dstEntityTypeID = isset($data['DST_ENTITY_TYPE_ID']) ? (int)$data['DST_ENTITY_TYPE_ID'] : \CCrmOwnerType::Undefined;
		$dstEntityID = isset($data['DST_ENTITY_ID']) ? (int)$data['DST_ENTITY_ID'] : 0;
		$dstRecycleBinID = isset($data['DST_RECYCLE_BIN_ID']) ? (int)$data['DST_RECYCLE_BIN_ID'] : null;

		$now = Main\Type\DateTime::createFromTimestamp(time() + \CTimeZone::GetOffset());

		$insertFields = [
			'SRC_ENTITY_TYPE_ID' => $srcEntityTypeID,
			'SRC_ENTITY_ID' => $srcEntityID,
			'SRC_RECYCLE_BIN_ID' => ($srcRecycleBinID !== null) ? $srcRecycleBinID : 0,
			'DST_ENTITY_TYPE_ID' => $dstEntityTypeID,
			'DST_ENTITY_ID' => $dstEntityID,
			'DST_RECYCLE_BIN_ID' => ($dstRecycleBinID !== null) ? $dstRecycleBinID : 0,
			'PREVIOUS_SRC_ENTITY_ID' => 0,
			'PREVIOUS_DST_ENTITY_ID' => 0,
			'CREATED_TIME' => $now,
			'LAST_UPDATED_TIME' => $now
		];

		$updateFields = [ 'LAST_UPDATED_TIME' => $now ];
		if($srcRecycleBinID !== null)
		{
			$updateFields['SRC_RECYCLE_BIN_ID'] = $srcRecycleBinID;
		}

		if($dstRecycleBinID !== null)
		{
			$updateFields['DST_RECYCLE_BIN_ID'] = $dstRecycleBinID;
		}

		$connection = Main\Application::getConnection();
		$queries = $connection->getSqlHelper()->prepareMerge(
			'b_crm_recycling_relation',
			[
				'SRC_ENTITY_TYPE_ID',
				'SRC_ENTITY_ID',
				'SRC_RECYCLE_BIN_ID',
				'DST_ENTITY_TYPE_ID',
				'DST_ENTITY_ID',
				'DST_RECYCLE_BIN_ID'
			],
			$insertFields,
			$updateFields
		);

		foreach($queries as $query)
		{
			$connection->queryExecute($query);
		}
	}

	public static function updateEntityID($entityTypeID, $oldEntityID, $newEntityID, $recyclingEntityID = 0)
	{
		if(!is_int($entityTypeID))
		{
			$entityTypeID = (int)$entityTypeID;
		}

		if(!is_int($oldEntityID))
		{
			$oldEntityID = (int)$oldEntityID;
		}

		if(!is_int($newEntityID))
		{
			$newEntityID = (int)$newEntityID;
		}

		if(!is_int($recyclingEntityID))
		{
			$recyclingEntityID = (int)$recyclingEntityID;
		}

		$connection = Main\Application::getConnection();

		if($recyclingEntityID > 0)
		{
			$connection->queryExecute(
				"UPDATE b_crm_recycling_relation 
				SET SRC_ENTITY_ID = {$newEntityID}, PREVIOUS_SRC_ENTITY_ID = {$oldEntityID} 
				WHERE SRC_ENTITY_TYPE_ID = {$entityTypeID} AND SRC_ENTITY_ID = {$oldEntityID} AND SRC_RECYCLE_BIN_ID = {$recyclingEntityID}"
			);
			$connection->queryExecute(
				"UPDATE b_crm_recycling_relation 
				SET DST_ENTITY_ID = {$newEntityID}, PREVIOUS_DST_ENTITY_ID = {$oldEntityID}  
				WHERE DST_ENTITY_TYPE_ID = {$entityTypeID} AND DST_ENTITY_ID = {$oldEntityID} AND DST_RECYCLE_BIN_ID = {$recyclingEntityID}"
			);
		}
		else
		{
			$connection->queryExecute(
				"UPDATE b_crm_recycling_relation 
				SET SRC_ENTITY_ID = {$newEntityID}, PREVIOUS_SRC_ENTITY_ID = {$oldEntityID} 
				WHERE SRC_ENTITY_TYPE_ID = {$entityTypeID} AND SRC_ENTITY_ID = {$oldEntityID}"
			);
			$connection->queryExecute(
				"UPDATE b_crm_recycling_relation 
				SET DST_ENTITY_ID = {$newEntityID}, PREVIOUS_DST_ENTITY_ID = {$oldEntityID}  
				WHERE DST_ENTITY_TYPE_ID = {$entityTypeID} AND DST_ENTITY_ID = {$oldEntityID}"
			);
		}
	}

	public static function registerRecycleBin($entityTypeID, $entityID, $recycleBinID)
	{
		if(!is_int($entityTypeID))
		{
			$entityTypeID = (int)$entityTypeID;
		}

		if(!is_int($entityID))
		{
			$entityID = (int)$entityID;
		}

		if($entityTypeID <= 0 || $entityID <= 0)
		{
			return;
		}

		if(!is_int($recycleBinID))
		{
			$recycleBinID = (int)$recycleBinID;
		}

		self::setRecycleBinID(
			'SRC_RECYCLE_BIN_ID',
			$recycleBinID,
			"SRC_ENTITY_TYPE_ID = {$entityTypeID} AND SRC_ENTITY_ID = {$entityID} AND SRC_RECYCLE_BIN_ID <= 0"
		);
		self::setRecycleBinID(
			'DST_RECYCLE_BIN_ID',
			$recycleBinID,
			"DST_ENTITY_TYPE_ID = {$entityTypeID} AND DST_ENTITY_ID = {$entityID} AND DST_RECYCLE_BIN_ID <= 0"
		);
	}

	public static function unregisterRecycleBin($recycleBinID)
	{
		if(!is_int($recycleBinID))
		{
			$recycleBinID = (int)$recycleBinID;
		}

		if($recycleBinID <= 0)
		{
			return;
		}

		self::setRecycleBinID('SRC_RECYCLE_BIN_ID', 0, "SRC_RECYCLE_BIN_ID = {$recycleBinID}");
		self::setRecycleBinID('DST_RECYCLE_BIN_ID', 0, "DST_RECYCLE_BIN_ID = {$recycleBinID}");
	}

	/**
	 * The recycle bin columns are part of the primary key, so the update can turn a row into an exact copy
	 * of an existing one: a parallel deletion of the other side of the relation saves such a copy between
	 * our upsert and this update. The copies are removed first; the update is retried if another one appears
	 * in between.
	 */
	private static function setRecycleBinID(string $binColumn, int $recycleBinID, string $condition): void
	{
		$connection = Main\Application::getConnection();

		for ($attempt = 1; ; $attempt++)
		{
			// a failed statement aborts the whole transaction on pgsql, the savepoint keeps the retry possible
			$connection->startTransaction();
			try
			{
				self::deleteRowsDuplicatedByUpdate($binColumn, $recycleBinID, $condition);
				$connection->queryExecute(
					"UPDATE b_crm_recycling_relation SET {$binColumn} = {$recycleBinID} WHERE {$condition}"
				);
				$connection->commitTransaction();

				return;
			}
			catch (\Throwable $exception)
			{
				$connection->rollbackTransaction();
				$canRetry = $exception instanceof Main\DB\DuplicateEntryException
					&& $attempt < self::MAX_SET_RECYCLE_BIN_ATTEMPTS
				;
				if (!$canRetry)
				{
					throw $exception;
				}
			}
		}
	}

	private static function deleteRowsDuplicatedByUpdate(string $binColumn, int $recycleBinID, string $condition): void
	{
		$twinConditions = [];
		foreach (self::PRIMARY_COLUMNS as $column)
		{
			$twinConditions[] = $column === $binColumn
				? "twin.{$column} = {$recycleBinID}"
				: "twin.{$column} = r.{$column}"
			;
		}

		$connection = Main\Application::getConnection();
		$rows = $connection->query(
			'SELECT r.' . implode(', r.', self::PRIMARY_COLUMNS) . ' FROM b_crm_recycling_relation r'
			. " WHERE {$condition} AND EXISTS ("
			. 'SELECT 1 FROM b_crm_recycling_relation twin WHERE ' . implode(' AND ', $twinConditions)
			. ')'
		);

		while ($row = $rows->fetch())
		{
			$keyConditions = [];
			foreach (array_combine(self::PRIMARY_COLUMNS, array_values($row)) as $column => $value)
			{
				$keyConditions[] = "{$column} = " . (int)$value;
			}

			$connection->queryExecute(
				'DELETE FROM b_crm_recycling_relation WHERE ' . implode(' AND ', $keyConditions)
			);
		}
	}

	public static function deleteByRecycleBin($recycleBinID)
	{
		if(!is_int($recycleBinID))
		{
			$recycleBinID = (int)$recycleBinID;
		}

		if($recycleBinID <= 0)
		{
			return;
		}

		Main\Application::getConnection()->queryExecute(
			"DELETE FROM b_crm_recycling_relation 
				WHERE SRC_RECYCLE_BIN_ID = {$recycleBinID} OR DST_RECYCLE_BIN_ID = {$recycleBinID}"
		);
	}

	public static function deleteJunks()
	{
		$connection = Main\Application::getConnection();
		$exists = $connection->query('SELECT SRC_RECYCLE_BIN_ID FROM b_crm_recycling_relation WHERE SRC_RECYCLE_BIN_ID = 0 AND DST_RECYCLE_BIN_ID = 0 LIMIT 1')->fetch();
		if ($exists)
		{
			$connection->queryExecute(
				"DELETE FROM b_crm_recycling_relation WHERE SRC_RECYCLE_BIN_ID = 0 AND DST_RECYCLE_BIN_ID = 0"
			);
		}
	}
}
