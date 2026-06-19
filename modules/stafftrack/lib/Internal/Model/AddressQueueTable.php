<?php

namespace Bitrix\StaffTrack\Internal\Model;

use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Data\Internal\DeleteByFilterTrait;
use Bitrix\Main\ORM\Fields\DatetimeField;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\ORM\Fields\StringField;

/**
 * Class AddressQueueTable
 *
 * DO NOT WRITE ANYTHING BELOW THIS
 *
 * <<< ORMENTITYANNOTATION
 * @method static EO_AddressQueue_Query query()
 * @method static EO_AddressQueue_Result getByPrimary($primary, array $parameters = [])
 * @method static EO_AddressQueue_Result getById($id)
 * @method static EO_AddressQueue_Result getList(array $parameters = [])
 * @method static EO_AddressQueue_Entity getEntity()
 * @method static \Bitrix\StaffTrack\Internal\Model\EO_AddressQueue createObject($setDefaultValues = true)
 * @method static \Bitrix\StaffTrack\Internal\Model\EO_AddressQueue_Collection createCollection()
 * @method static \Bitrix\StaffTrack\Internal\Model\EO_AddressQueue wakeUpObject($row)
 * @method static \Bitrix\StaffTrack\Internal\Model\EO_AddressQueue_Collection wakeUpCollection($rows)
 */
class AddressQueueTable extends DataManager
{
	use DeleteByFilterTrait;

	public static function getTableName(): string
	{
		return 'b_stafftrack_address_queue';
	}

	public static function getMap(): array
	{
		return [
			(new IntegerField('CHECK_IN_ID'))
				->configurePrimary(true)
			,
			(new IntegerField('VIEWER_ID'))
				->configurePrimary(true)
				->configureDefaultValue(0)
			,
			(new StringField('GEOHASH'))
				->configureSize(8)
				->configureRequired(true)
			,
			(new IntegerField('USER_ID'))
				->configureRequired(true)
			,
			(new DatetimeField('DATE_CREATE'))
				->configureRequired(true)
			,
		];
	}
}
