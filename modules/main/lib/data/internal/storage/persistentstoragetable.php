<?php

namespace Bitrix\Main\Data\Internal\Storage;

use Bitrix\Main\ORM\Data\AddStrategy\Trait\MergeByDefaultTrait;
use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Fields;
use Bitrix\Main\ORM\Data\Internal\DeleteByFilterTrait;

/**
 * Class PersistentStorageTable
 *
 * DO NOT WRITE ANYTHING BELOW THIS
 *
 * <<< ORMENTITYANNOTATION
 * @method static EO_PersistentStorage_Query query()
 * @method static EO_PersistentStorage_Result getByPrimary($primary, array $parameters = [])
 * @method static EO_PersistentStorage_Result getById($id)
 * @method static EO_PersistentStorage_Result getList(array $parameters = [])
 * @method static EO_PersistentStorage_Entity getEntity()
 * @method static \Bitrix\Main\Data\Internal\Storage\EO_PersistentStorage createObject($setDefaultValues = true)
 * @method static \Bitrix\Main\Data\Internal\Storage\EO_PersistentStorage_Collection createCollection()
 * @method static \Bitrix\Main\Data\Internal\Storage\EO_PersistentStorage wakeUpObject($row)
 * @method static \Bitrix\Main\Data\Internal\Storage\EO_PersistentStorage_Collection wakeUpCollection($rows)
 */
class PersistentStorageTable extends DataManager
{
	use DeleteByFilterTrait;
	use MergeByDefaultTrait;

	public static function getTableName()
	{
		return 'b_persistent_storage';
	}

	public static function getMap()
	{
		return [
			(new Fields\StringField('KEY'))
				->configurePrimary()
				->configureSize(255)
			,

			(new Fields\JsonField('VALUE'))
				->configureNullable(false)
			,

			(new Fields\DatetimeField('CREATED_AT'))
				->configureDefaultValueNow()
			,

			(new Fields\DatetimeField('EXPIRED_AT'))
				->configureRequired()
			,
		];
	}
}
