<?php

namespace Bitrix\Disk\Internals\Index;

use Bitrix\Disk\Configuration;
use Bitrix\Main\ORM\Data\AddStrategy\Trait\AddInsertIgnoreTrait;
use Bitrix\Main\ORM\Fields;
use Bitrix\Main\SystemException;
use Bitrix\Main\Type\DateTime;

/**
 * Class ObjectExtendedIndexTable
 *
 * DO NOT WRITE ANYTHING BELOW THIS
 *
 * <<< ORMENTITYANNOTATION
 * @method static EO_ObjectExtendedIndex_Query query()
 * @method static EO_ObjectExtendedIndex_Result getByPrimary($primary, array $parameters = [])
 * @method static EO_ObjectExtendedIndex_Result getById($id)
 * @method static EO_ObjectExtendedIndex_Result getList(array $parameters = [])
 * @method static EO_ObjectExtendedIndex_Entity getEntity()
 * @method static \Bitrix\Disk\Internals\Index\EO_ObjectExtendedIndex createObject($setDefaultValues = true)
 * @method static \Bitrix\Disk\Internals\Index\EO_ObjectExtendedIndex_Collection createCollection()
 * @method static \Bitrix\Disk\Internals\Index\EO_ObjectExtendedIndex wakeUpObject($row)
 * @method static \Bitrix\Disk\Internals\Index\EO_ObjectExtendedIndex_Collection wakeUpCollection($rows)
 */
class ObjectExtendedIndexTable extends BaseIndexTable
{
	use AddInsertIgnoreTrait;

	public const STATUS_SHORT    = 2;
	public const STATUS_EXTENDED = 3;
	public const STATUS_BOARD_WAITING = 4;
	public const STATUS_BOARD_READY = 5;
	public const STATUS_BOARD_FAILED = 6;

	public static function getMap()
	{
		return array_merge(parent::getMap(), [
			(new Fields\EnumField('STATUS'))
				->configureValues([
					static::STATUS_SHORT,
					static::STATUS_EXTENDED,
					static::STATUS_BOARD_WAITING,
					static::STATUS_BOARD_READY,
					static::STATUS_BOARD_FAILED,
				])
				->configureDefaultValue(static::STATUS_SHORT),
		]);
	}

	public static function getTableName()
	{
		return 'b_disk_object_extended_index';
	}

	public static function getMaxIndexSize()
	{
		return Configuration::getMaxExtendedIndexSize();
	}

	public static function upsert($objectId, $searchIndex, $status = self::STATUS_SHORT)
	{
		$objectId = (int)$objectId;
		$searchIndex = trim($searchIndex);

		static::mergeOrSkip([
			'OBJECT_ID' => $objectId,
			'SEARCH_INDEX' => $searchIndex,
			'UPDATE_TIME' => new DateTime(),
			'STATUS' => $status,
		]);
	}

	public static function markBoardWaiting(int $objectId, string $searchIndex): void
	{
		$status = static::query()
			->setSelect(['STATUS'])
			->where('OBJECT_ID', $objectId)
			->fetch()['STATUS'] ?? null
		;
		if ((int)$status === self::STATUS_BOARD_WAITING)
		{
			return;
		}

		$now = new DateTime();

		$result = static::addInsertIgnore([
			'OBJECT_ID' => $objectId,
			'SEARCH_INDEX' => $searchIndex,
			'UPDATE_TIME' => $now,
			'STATUS' => self::STATUS_BOARD_WAITING,
		]);
		if (!$result->isSuccess())
		{
			throw new SystemException('Could not mark board index as waiting: ' . implode('; ', $result->getErrorMessages()));
		}

		static::updateBatch(
			[
				'STATUS' => self::STATUS_BOARD_WAITING,
				'UPDATE_TIME' => $now,
			],
			[
				'=OBJECT_ID' => $objectId,
				'!=STATUS' => self::STATUS_BOARD_WAITING,
			],
		);
	}

	public static function changeStatus($objectId, $status)
	{
		$objectId = (int)$objectId;

		static::merge([
			'OBJECT_ID' => $objectId,
			'UPDATE_TIME' => new DateTime(),
			'STATUS' => $status,
		]);
	}

	/**
	 * @internal The caller must lock and validate the object and its waiting index in one transaction.
	 */
	public static function publishBoardIndex(int $objectId, string $searchIndex, ?array &$truncation = null): void
	{
		$truncation = null;
		$originalBytes = strlen($searchIndex);
		$limitBytes = (int)static::getMaxIndexSize();
		$searchIndex = mb_strcut($searchIndex, 0, $limitBytes, 'UTF-8');
		$result = static::update($objectId, [
			'SEARCH_INDEX' => $searchIndex,
			'STATUS' => self::STATUS_BOARD_READY,
			'UPDATE_TIME' => new DateTime(),
		]);
		if (!$result->isSuccess())
		{
			throw new SystemException('Could not publish board index.');
		}

		$indexedBytes = strlen($searchIndex);
		if ($indexedBytes < $originalBytes)
		{
			$truncation = [
				'originalBytes' => $originalBytes,
				'indexedBytes' => $indexedBytes,
				'limitBytes' => $limitBytes,
			];
		}
	}

	/**
	 * @internal The caller must lock and validate the object and its waiting index in one transaction.
	 */
	public static function markBoardIndexFailed(int $objectId): void
	{
		$result = static::update($objectId, [
			'STATUS' => self::STATUS_BOARD_FAILED,
			'UPDATE_TIME' => new DateTime(),
		]);
		if (!$result->isSuccess())
		{
			throw new SystemException('Could not mark board index as failed.');
		}
	}
}
