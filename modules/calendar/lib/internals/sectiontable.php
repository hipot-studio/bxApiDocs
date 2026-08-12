<?php

namespace Bitrix\Calendar\Internals;

use Bitrix\Main;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\ORM\Fields\BooleanField;
use Bitrix\Main\ORM\Fields\DatetimeField;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\ORM\Fields\StringField;
use Bitrix\Main\ORM\Fields\TextField;
use Bitrix\Main\ORM\Fields\Validators\LengthValidator;

/**
 * Class SectionTable
 *
 * Fields:
 * <ul>
 * <li> ID int mandatory
 * <li> NAME string(255) optional
 * <li> XML_ID string(100) optional
 * <li> EXTERNAL_ID string(100) optional
 * <li> ACTIVE bool optional default 'Y'
 * <li> DESCRIPTION string optional
 * <li> COLOR string(10) optional
 * <li> TEXT_COLOR string(10) optional
 * <li> EXPORT string(255) optional
 * <li> SORT int optional default 100
 * <li> CAL_TYPE string(100) optional
 * <li> OWNER_ID int optional
 * <li> CREATED_BY int mandatory
 * <li> PARENT_ID int optional
 * <li> DATE_CREATE datetime optional
 * <li> TIMESTAMP_X datetime optional
 * <li> DAV_EXCH_CAL string(255) optional
 * <li> DAV_EXCH_MOD string(255) optional
 * <li> CAL_DAV_CON string(255) optional
 * <li> CAL_DAV_CAL string(255) optional
 * <li> CAL_DAV_MOD string(255) optional
 * <li> IS_EXCHANGE string(1) optional
 * <li> GAPI_CALENDAR_ID string(255) optional
 * <li> SYNC_TOKEN string(255) optional
 * <li> PAGE_TOKEN string(255) optional
 * <li> EXTERNAL_TYPE string(20) optional
 * </ul>
 *
 * @package Bitrix\Calendar
 *
 * DO NOT WRITE ANYTHING BELOW THIS
 *
 * <<< ORMENTITYANNOTATION
 * @method static EO_Section_Query query()
 * @method static EO_Section_Result getByPrimary($primary, array $parameters = [])
 * @method static EO_Section_Result getById($id)
 * @method static EO_Section_Result getList(array $parameters = [])
 * @method static EO_Section_Entity getEntity()
 * @method static \Bitrix\Calendar\Internals\EO_Section createObject($setDefaultValues = true)
 * @method static \Bitrix\Calendar\Internals\EO_Section_Collection createCollection()
 * @method static \Bitrix\Calendar\Internals\EO_Section wakeUpObject($row)
 * @method static \Bitrix\Calendar\Internals\EO_Section_Collection wakeUpCollection($rows)
 */
class SectionTable extends Main\Entity\DataManager
{
	/**
	 * Returns DB table name for entity.
	 *
	 * @return string
	 */
	public static function getTableName()
	{
		return 'b_calendar_section';
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
			(new StringField('NAME',
				[
					'validation' => [__CLASS__, 'validateName']
				]
			))
			,
			(new StringField('XML_ID',
				[
					'validation' => [__CLASS__, 'validateXmlId']
				]
			))
			,
			(new StringField('EXTERNAL_ID',
				[
					'validation' => [__CLASS__, 'validateExternalId']
				]
			))
			,
			(new StringField('GAPI_CALENDAR_ID',
				[
					'validation' => [__CLASS__, 'validateGapiCalendarId']
				]
			))
			,
			(new BooleanField('ACTIVE'))
				->configureValues('N', 'Y')
				->configureDefaultValue('Y')
			,
			(new TextField('DESCRIPTION'))
			,
			(new StringField('COLOR',
				[
					'validation' => [__CLASS__, 'validateColor']
				]
			))
			,
			(new StringField('TEXT_COLOR',
				[
					'validation' => [__CLASS__, 'validateTextColor']
				]
			))
			,
			(new StringField('EXPORT',
				[
					'validation' => [__CLASS__, 'validateExport']
				]
			))
			,
			(new IntegerField('SORT'))

				->configureDefaultValue(100)
			,
			(new StringField('CAL_TYPE',
				[
					'validation' => [__CLASS__, 'validateCalType']
				]
			))
			,
			(new IntegerField('OWNER_ID'))
			,
			(new IntegerField('CREATED_BY'))
				->configureRequired(true)
			,
			(new IntegerField('PARENT_ID'))
			,
			(new DatetimeField('DATE_CREATE'))
			,
			(new DatetimeField('TIMESTAMP_X'))
			,
			(new StringField('DAV_EXCH_CAL',
				[
					'validation' => [__CLASS__, 'validateDavExchCal']
				]
			))
			,
			(new StringField('DAV_EXCH_MOD',
				[
					'validation' => [__CLASS__, 'validateDavExchMod']
				]
			))
			,
			(new StringField('CAL_DAV_CON',
				[
					'validation' => [__CLASS__, 'validateCalDavCon']
				]
			))
			,
			(new StringField('CAL_DAV_CAL',
				[
					'validation' => [__CLASS__, 'validateCalDavCal']
				]
			))
			,
			(new StringField('CAL_DAV_MOD',
				[
					'validation' => [__CLASS__, 'validateCalDavMod']
				]
			))
			,
			(new StringField('IS_EXCHANGE',
				[
					'validation' => [__CLASS__, 'validateIsExchange']
				]
			))
			,
			(new StringField('SYNC_TOKEN',
				[
					'validation' => [__CLASS__, 'validateSyncToken']
				]
			))
			,
			(new StringField('PAGE_TOKEN',
				[
					'validation' => [__CLASS__, 'validatePageToken']
				]
			))
			,
			(new StringField('EXTERNAL_TYPE',
				[
					'validation' => [__CLASS__, 'validateExternalType']
				]
			))
			,
		];
	}

	/**
	 * Returns validators for NAME field.
	 *
	 * @return array
	 */
	public static function validateName(): array
	{
		return [
			new LengthValidator(null, 255),
		];
	}

	/**
	 * Returns validators for XML_ID field.
	 *
	 * @return array
	 */
	public static function validateXmlId(): array
	{
		return [
			new LengthValidator(null, 100),
		];
	}

	/**
	 * Returns validators for EXTERNAL_ID field.
	 *
	 * @return array
	 */
	public static function validateExternalId(): array
	{
		return [
			new LengthValidator(null, 100),
		];
	}

	/**
	 * Returns validators for GAPI_CALENDAR_ID field.
	 *
	 * @return array
	 */
	public static function validateGapiCalendarId(): array
	{
		return [
			new LengthValidator(null, 255),
		];
	}

	/**
	 * Returns validators for COLOR field.
	 *
	 * @return array
	 */
	public static function validateColor(): array
	{
		return [
			new LengthValidator(null, 10),
		];
	}

	/**
	 * Returns validators for TEXT_COLOR field.
	 *
	 * @return array
	 */
	public static function validateTextColor(): array
	{
		return [
			new LengthValidator(null, 10),
		];
	}

	/**
	 * Returns validators for EXPORT field.
	 *
	 * @return array
	 */
	public static function validateExport(): array
	{
		return [
			new LengthValidator(null, 255),
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
	 * Returns validators for DAV_EXCH_CAL field.
	 *
	 * @return array
	 */
	public static function validateDavExchCal(): array
	{
		return [
			new LengthValidator(null, 255),
		];
	}

	/**
	 * Returns validators for DAV_EXCH_MOD field.
	 *
	 * @return array
	 */
	public static function validateDavExchMod(): array
	{
		return [
			new LengthValidator(null, 255),
		];
	}

	/**
	 * Returns validators for CAL_DAV_CON field.
	 *
	 * @return array
	 */
	public static function validateCalDavCon(): array
	{
		return [
			new LengthValidator(null, 255),
		];
	}

	/**
	 * Returns validators for CAL_DAV_CAL field.
	 *
	 * @return array
	 */
	public static function validateCalDavCal(): array
	{
		return [
			new LengthValidator(null, 255),
		];
	}

	/**
	 * Returns validators for CAL_DAV_MOD field.
	 *
	 * @return array
	 */
	public static function validateCalDavMod(): array
	{
		return [
			new LengthValidator(null, 255),
		];
	}

	/**
	 * Returns validators for IS_EXCHANGE field.
	 *
	 * @return array
	 */
	public static function validateIsExchange(): array
	{
		return [
			new LengthValidator(null, 1),
		];
	}

	/**
	 * Returns validators for SYNC_TOKEN field.
	 *
	 * @return array
	 */
	public static function validateSyncToken(): array
	{
		return [
			new LengthValidator(null, 255),
		];
	}

	/**
	 * Returns validators for PAGE_TOKEN field.
	 *
	 * @return array
	 */
	public static function validatePageToken(): array
	{
		return [
			new LengthValidator(null, 255),
		];
	}

	/**
	 * Returns validators for EXTERNAL_TYPE field.
	 *
	 * @return array
	 */
	public static function validateExternalType(): array
	{
		return [
			new LengthValidator(null, 20),
		];
	}
}
