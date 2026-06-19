<?php

namespace Bitrix\StaffTrack\Internal\Model;

use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Data\Internal\DeleteByFilterTrait;
use Bitrix\Main\ORM\Fields\DatetimeField;
use Bitrix\Main\ORM\Fields\FloatField;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\ORM\Fields\StringField;
use Bitrix\Main\ORM\Fields\JsonField;
use Bitrix\Main\Type\DateTime;

/**
 * Class CheckInTable
 *
 * <<< ORMENTITYANNOTATION
 * @method static EO_CheckIn_Query query()
 * @method static EO_CheckIn_Result getByPrimary($primary, array $parameters = [])
 * @method static EO_CheckIn_Result getById($id)
 * @method static EO_CheckIn_Result getList(array $parameters = [])
 * @method static EO_CheckIn_Entity getEntity()
 * @method static \Bitrix\StaffTrack\Internal\Model\CheckIn createObject($setDefaultValues = true)
 * @method static \Bitrix\StaffTrack\Internal\Model\CheckInCollection createCollection()
 * @method static \Bitrix\StaffTrack\Internal\Model\CheckIn wakeUpObject($row)
 * @method static \Bitrix\StaffTrack\Internal\Model\CheckInCollection wakeUpCollection($rows)
 */
class CheckInTable extends DataManager
{
	use DeleteByFilterTrait;

	public static function getTableName()
	{
		return 'b_stafftrack_check_in';
	}

	public static function getObjectClass(): string
	{
		return CheckIn::class;
	}

	public static function getCollectionClass(): string
	{
		return CheckInCollection::class;
	}

	public static function getMap()
	{
		return [
			(new IntegerField('ID'))
				->configurePrimary(true)
				->configureAutocomplete(true)
			,
			(new IntegerField('USER_ID'))
				->configureRequired(true)
			,
			(new DatetimeField('DATE_CREATE'))
				->configureDefaultValue(static fn() => new DateTime())
			,
			(new IntegerField('ENTITY_TYPE'))
				->configureRequired(true)
			,
			(new FloatField('LATITUDE'))
				->configureNullable(true)
			,
			(new FloatField('LONGITUDE'))
				->configureNullable(true)
			,
			(new StringField('DESCRIPTION'))
				->configureSize(255)
				->configureNullable(true)
			,
			(new StringField('ADDRESS'))
				->configureSize(255)
				->configureNullable(true)
			,
			(new JsonField('MESSAGE_IDS'))
				->configureNullable()
				->configureDefaultValue('')
			,
			(new IntegerField('USER_TIMEZONE'))
				->configureNullable(true)
			,
			(new JsonField('FILE_IDS'))
				->configureNullable()
				->configureDefaultValue('')
			,
		];
	}

	/**
	 * @param int[] $ids
	 */
	public static function deleteByIds(array $ids): void
	{
		$ids = array_filter(array_map('intval', $ids));

		if (empty($ids))
		{
			return;
		}

		static::deleteByFilter(['@ID' => $ids]);
	}
}
