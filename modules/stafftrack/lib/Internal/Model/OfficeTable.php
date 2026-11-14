<?php

namespace Bitrix\StaffTrack\Internal\Model;

use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Fields\DatetimeField;
use Bitrix\Main\ORM\Fields\FloatField;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\ORM\Fields\StringField;
use Bitrix\Main\Type\DateTime;

/**
 * Class OfficeTable
 *
 * Coordinates are stored as plain numbers, unlike the ones of a check-in point: an office is picked
 * by the frame of a map, and an encrypted column cannot be compared by a range.
 *
 * DO NOT WRITE ANYTHING BELOW THIS
 *
 * <<< ORMENTITYANNOTATION
 * @method static EO_Office_Query query()
 * @method static EO_Office_Result getByPrimary($primary, array $parameters = [])
 * @method static EO_Office_Result getById($id)
 * @method static EO_Office_Result getList(array $parameters = [])
 * @method static EO_Office_Entity getEntity()
 * @method static \Bitrix\StaffTrack\Internal\Model\Office createObject($setDefaultValues = true)
 * @method static \Bitrix\StaffTrack\Internal\Model\OfficeCollection createCollection()
 * @method static \Bitrix\StaffTrack\Internal\Model\Office wakeUpObject($row)
 * @method static \Bitrix\StaffTrack\Internal\Model\OfficeCollection wakeUpCollection($rows)
 */
class OfficeTable extends DataManager
{
	public static function getTableName()
	{
		return 'b_stafftrack_office';
	}

	public static function getObjectClass(): string
	{
		return Office::class;
	}

	public static function getCollectionClass(): string
	{
		return OfficeCollection::class;
	}

	public static function getMap()
	{
		return [
			(new IntegerField('ID'))
				->configurePrimary(true)
				->configureAutocomplete(true)
			,
			(new StringField('TITLE'))
				->configureRequired(true)
				->configureSize(255)
			,
			(new FloatField('LATITUDE'))
				->configureRequired(true)
			,
			(new FloatField('LONGITUDE'))
				->configureRequired(true)
			,
			(new IntegerField('GEO_RADIUS'))
				->configureRequired(true)
			,
			(new StringField('ADDRESS'))
				->configureSize(500)
				->configureNullable()
			,
			// Minutes from midnight, both ends optional.
			(new IntegerField('WORK_TIME_FROM'))
				->configureNullable()
			,
			(new IntegerField('WORK_TIME_TO'))
				->configureNullable()
			,
			(new IntegerField('CREATED_BY'))
				->configureRequired(true)
			,
			(new IntegerField('UPDATED_BY'))
				->configureNullable()
			,
			(new DatetimeField('DATE_CREATE'))
				->configureDefaultValue(static fn() => new DateTime())
			,
			(new DatetimeField('DATE_UPDATE'))
				->configureNullable()
			,
			(new IntegerField('SNAPSHOT_FILE_ID'))
				->configureNullable()
			,
		];
	}
}
