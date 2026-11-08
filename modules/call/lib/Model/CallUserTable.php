<?php

namespace Bitrix\Call\Model;

use Bitrix\Main\Application;
use Bitrix\Main\Error;
use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\ORM\Fields\StringField;
use Bitrix\Main\ORM\Fields\DatetimeField;
use Bitrix\Main\ORM\Fields\BooleanField;
use Bitrix\Main\ORM\Data\AddResult;

/**
 * Class CallUserTable
 *
 * DO NOT WRITE ANYTHING BELOW THIS
 *
 * <<< ORMENTITYANNOTATION
 * @method static EO_CallUser_Query query()
 * @method static EO_CallUser_Result getByPrimary($primary, array $parameters = [])
 * @method static EO_CallUser_Result getById($id)
 * @method static EO_CallUser_Result getList(array $parameters = [])
 * @method static EO_CallUser_Entity getEntity()
 * @method static \Bitrix\Call\Model\EO_CallUser createObject($setDefaultValues = true)
 * @method static \Bitrix\Call\Model\EO_CallUser_Collection createCollection()
 * @method static \Bitrix\Call\Model\EO_CallUser wakeUpObject($row)
 * @method static \Bitrix\Call\Model\EO_CallUser_Collection wakeUpCollection($rows)
 */
class CallUserTable extends DataManager
{
	public static function getTableName(): string
	{
		//todo remove migration option check
		static $tableName = null;
		if ($tableName === null)
		{
			$tableName = \Bitrix\Main\Config\Option::get('call', 'call_db_migrated', 0) >= 5
				? 'b_call_user'
				: 'b_im_call_user';
		}
		return $tableName;
	}

	/**
	 * Bulk-set the state of the given users of a call.
	 *
	 * @param int[] $userIds
	 * @param string|null $fromState Only rows currently in this state are updated
	 *        (compare-and-set). Pass the expected source state on off-lock paths so
	 *        a concurrent transition is not overwritten.
	 * @return int[] User ids actually transitioned by this call. With $fromState it
	 *        is the subset that was in $fromState and got flipped, resolved atomically
	 *        under a row lock (SELECT ... FOR UPDATE inside a transaction) so the
	 *        returned set equals the set the UPDATE wrote — callers emit state-change
	 *        events only for real transitions, never for a user a concurrent
	 *        answer/decline/hangup moved out of $fromState. Without $fromState it is
	 *        the full requested set (unconditional update).
	 */
	public static function transitionState(int $callId, array $userIds, string $toState, ?string $fromState = null): array
	{
		if ($callId <= 0)
		{
			return [];
		}

		$userIds = array_values(array_filter(
			array_unique(array_map('intval', $userIds)),
			static fn (int $userId): bool => $userId > 0,
		));
		if ($userIds === [])
		{
			return [];
		}

		$connection = Application::getConnection();
		$sqlHelper = $connection->getSqlHelper();
		$tableName = static::getTableName();
		$quotedTable = $sqlHelper->quote($tableName);
		$entity = static::getEntity();

		[$updateClause] = $sqlHelper->prepareUpdate($tableName, ['STATE' => $toState]);
		if ($updateClause === '')
		{
			return [];
		}

		// Unconditional update: flip every requested row regardless of its current
		// state. The returned set is exactly the requested ids.
		if ($fromState === null)
		{
			$whereClause = \Bitrix\Main\ORM\Query\Query::buildFilterSql($entity, [
				'=CALL_ID' => $callId,
				'@USER_ID' => $userIds,
			]);
			if ($whereClause === '')
			{
				return [];
			}

			$connection->queryExecute("UPDATE {$quotedTable} SET {$updateClause} WHERE {$whereClause}");

			return $userIds;
		}

		// Compare-and-set for off-lock callers. The returned set must be exactly the
		// rows this call flips: callers dispatch state-change events (e.g. the
		// "missed" call-log entry) only for real transitions. Reading which rows are
		// in $fromState and flipping them has to be atomic — a concurrent
		// answer/decline/hangup could move a user out of $fromState between a plain
		// read and the UPDATE, and the method would then report a transition that
		// never happened. Lock the requested participant rows by primary key, read
		// their live state under the lock, then update exactly the locked rows still
		// in $fromState, so the returned set equals the written set.
		$lockWhere = \Bitrix\Main\ORM\Query\Query::buildFilterSql($entity, [
			'=CALL_ID' => $callId,
			'@USER_ID' => $userIds,
		]);
		if ($lockWhere === '')
		{
			return [];
		}

		$userIdColumn = $sqlHelper->quote('USER_ID');
		$stateColumn = $sqlHelper->quote('STATE');

		$transitioned = [];
		$connection->startTransaction();
		try
		{
			$rows = $connection
				->query("SELECT {$userIdColumn}, {$stateColumn} FROM {$quotedTable} WHERE {$lockWhere} FOR UPDATE")
				->fetchAll()
			;
			foreach ($rows as $row)
			{
				if ((string)$row['STATE'] === $fromState)
				{
					$transitioned[] = (int)$row['USER_ID'];
				}
			}

			if ($transitioned !== [])
			{
				$updateWhere = \Bitrix\Main\ORM\Query\Query::buildFilterSql($entity, [
					'=CALL_ID' => $callId,
					'@USER_ID' => $transitioned,
				]);
				$connection->queryExecute("UPDATE {$quotedTable} SET {$updateClause} WHERE {$updateWhere}");
			}

			$connection->commitTransaction();
		}
		catch (\Throwable $exception)
		{
			$connection->rollbackTransaction();
			throw $exception;
		}

		return $transitioned;
	}

	public static function getMap(): array
	{
		return [
			new IntegerField('CALL_ID', [
				'primary' => true
			]),
			new IntegerField('USER_ID', [
				'primary' => true
			]),
			new StringField('STATE'),
			new DatetimeField('FIRST_JOINED'),
			new DatetimeField('LAST_SEEN'),
			new BooleanField('IS_MOBILE', [
				'values' => ['N', 'Y']
			]),
			new BooleanField('SHARED_SCREEN', [
				'values' => ['N', 'Y']
			]),
			new BooleanField('RECORDED', [
				'values' => ['N', 'Y']
			]),
		];
	}

	/**
	 * Inserts new record into the table, or updates existing record, if record is already found in the table.
	 *
	 * @param array $data Record to be merged to the table.
	 * @return AddResult
	 */
	public static function merge(array $data): AddResult
	{
		$result = new AddResult();

		$helper = Application::getConnection()->getSqlHelper();
		$insertData = $data;
		$updateData = $data;
		$mergeFields = static::getMergeFields();
		foreach ($mergeFields as $field)
		{
			unset($updateData[$field]);
		}
		$merge = $helper->prepareMerge(
			static::getTableName(),
			static::getMergeFields(),
			$insertData,
			$updateData
		);

		if ($merge[0] != "")
		{
			Application::getConnection()->query($merge[0]);
			$id = Application::getConnection()->getInsertedId();
			$result->setId($id);
			$result->setData($data);
		}
		else
		{
			$result->addError(new Error('Error constructing query'));
		}

		return $result;
	}

	/**
	 * Should return array of names of fields, that should be used to detect record duplication.
	 * @return array;
	 */
	protected static function getMergeFields(): array
	{
		return ['CALL_ID', 'USER_ID'];
	}
}
