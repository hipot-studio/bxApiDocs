<?php

namespace Bitrix\Calendar\Internals;

use Bitrix\Main;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\ORM\Fields\DatetimeField;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\ORM\Fields\StringField;
use Bitrix\Main\ORM\Fields\Validators\LengthValidator;

Loc::loadMessages(__FILE__);

/**
 * Class ResourceTable
 *
 * Fields:
 * <ul>
 * <li> ID int mandatory
 * <li> EVENT_ID int optional
 * <li> CAL_TYPE string(100) optional
 * <li> RESOURCE_ID int mandatory
 * <li> PARENT_TYPE string(100) optional
 * <li> PARENT_ID int mandatory
 * <li> UF_ID int optional
 * <li> DATE_FROM_UTC datetime optional
 * <li> DATE_TO_UTC datetime optional
 * <li> DATE_FROM datetime optional
 * <li> DATE_TO datetime optional
 * <li> DURATION int optional
 * <li> SKIP_TIME string(1) optional
 * <li> TZ_FROM string(50) optional
 * <li> TZ_TO string(50) optional
 * <li> TZ_OFFSET_FROM int optional
 * <li> TZ_OFFSET_TO int optional
 * <li> CREATED_BY int mandatory
 * <li> DATE_CREATE datetime optional
 * <li> TIMESTAMP_X datetime optional
 * <li> SERVICE_NAME string(200) optional
 * </ul>
 *
 * @package Bitrix\Calendar
 *
 * DO NOT WRITE ANYTHING BELOW THIS
 *
 * <<< ORMENTITYANNOTATION
 * @method static EO_Resource_Query query()
 * @method static EO_Resource_Result getByPrimary($primary, array $parameters = [])
 * @method static EO_Resource_Result getById($id)
 * @method static EO_Resource_Result getList(array $parameters = [])
 * @method static EO_Resource_Entity getEntity()
 * @method static \Bitrix\Calendar\Internals\EO_Resource createObject($setDefaultValues = true)
 * @method static \Bitrix\Calendar\Internals\EO_Resource_Collection createCollection()
 * @method static \Bitrix\Calendar\Internals\EO_Resource wakeUpObject($row)
 * @method static \Bitrix\Calendar\Internals\EO_Resource_Collection wakeUpCollection($rows)
 */
class ResourceTable extends Main\Entity\DataManager
{
	/**
	 * Returns DB table name for entity.
	 *
	 * @return string
	 */
	public static function getTableName()
	{
		return 'b_calendar_resource';
	}

	/**
	 * Returns entity map definition.
	 *
	 * @return array
	 */
	public static function getMap()
	{
		return [
			(new IntegerField('ID'))
				->configurePrimary(true)
				->configureAutocomplete(true)
			,
			(new IntegerField('EVENT_ID'))
			,
			(new StringField('CAL_TYPE',
				[
					'validation' => [__CLASS__, 'validateCalType']
				]
			))
			,
			(new IntegerField('RESOURCE_ID'))
				->configureRequired(true)
			,
			(new StringField('PARENT_TYPE',
				[
					'validation' => [__CLASS__, 'validateParentType']
				]
			))
			,
			(new IntegerField('PARENT_ID'))
				->configureRequired(true)
			,
			(new IntegerField('UF_ID'))
			,
			(new DatetimeField('DATE_FROM_UTC'))
			,
			(new DatetimeField('DATE_TO_UTC'))
			,
			(new DatetimeField('DATE_FROM'))
			,
			(new DatetimeField('DATE_TO'))
			,
			(new IntegerField('DURATION'))
			,
			(new StringField('SKIP_TIME',
				[
					'validation' => [__CLASS__, 'validateSkipTime']
				]
			))
			,
			(new StringField('TZ_FROM',
				[
					'validation' => [__CLASS__, 'validateTzFrom']
				]
			))
			,
			(new StringField('TZ_TO',
				[
					'validation' => [__CLASS__, 'validateTzTo']
				]
			))
			,
			(new IntegerField('TZ_OFFSET_FROM'))
			,
			(new IntegerField('TZ_OFFSET_TO'))
			,
			(new IntegerField('CREATED_BY'))
				->configureRequired(true)
			,
			(new DatetimeField('DATE_CREATE'))
			,
			(new DatetimeField('TIMESTAMP_X'))
			,
			(new StringField('SERVICE_NAME',
				[
					'validation' => [__CLASS__, 'validateServiceName']
				]
			))
			,
		];
	}

	/**
	 * Returns validators for CAL_TYPE field.
	 *
	 * @return array
	 */
	public static function validateCalType(): array
	{
		return [
			new LengthValidator(null, 100),
		];
	}

	/**
	 * Returns validators for PARENT_TYPE field.
	 *
	 * @return array
	 */
	public static function validateParentType(): array
	{
		return [
			new LengthValidator(null, 100),
		];
	}

	/**
	 * Returns validators for SKIP_TIME field.
	 *
	 * @return array
	 */
	public static function validateSkipTime(): array
	{
		return [
			new LengthValidator(null, 1),
		];
	}

	/**
	 * Returns validators for TZ_FROM field.
	 *
	 * @return array
	 */
	public static function validateTzFrom(): array
	{
		return [
			new LengthValidator(null, 50),
		];
	}

	/**
	 * Returns validators for TZ_TO field.
	 *
	 * @return array
	 */
	public static function validateTzTo(): array
	{
		return [
			new LengthValidator(null, 50),
		];
	}

	/**
	 * Returns validators for SERVICE_NAME field.
	 *
	 * @return array
	 */
	public static function validateServiceName(): array
	{
		return [
			new LengthValidator(null, 200),
		];
	}
}
