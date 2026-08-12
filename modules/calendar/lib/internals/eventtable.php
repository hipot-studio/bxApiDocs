<?php

namespace Bitrix\Calendar\Internals;

use Bitrix\Calendar\Internals\Trait\UpdateByFilterTrait;
use Bitrix\Calendar\Util;
use Bitrix\Main;
use Bitrix\Main\Entity\ReferenceField;
use Bitrix\Main\ORM\Data\Internal\DeleteByFilterTrait;
use Bitrix\Main\ORM\Query\Join;
use Bitrix\Main\ORM\Fields\BooleanField;
use Bitrix\Main\ORM\Fields\DatetimeField;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\ORM\Fields\StringField;
use Bitrix\Main\ORM\Fields\TextField;
use Bitrix\Main\ORM\Fields\Validators\LengthValidator;

/**
 * Class EventTable
 *
 * DO NOT WRITE ANYTHING BELOW THIS
 *
 * <<< ORMENTITYANNOTATION
 * @method static EO_Event_Query query()
 * @method static EO_Event_Result getByPrimary($primary, array $parameters = [])
 * @method static EO_Event_Result getById($id)
 * @method static EO_Event_Result getList(array $parameters = [])
 * @method static EO_Event_Entity getEntity()
 * @method static \Bitrix\Calendar\Internals\EO_Event createObject($setDefaultValues = true)
 * @method static \Bitrix\Calendar\Internals\EO_Event_Collection createCollection()
 * @method static \Bitrix\Calendar\Internals\EO_Event wakeUpObject($row)
 * @method static \Bitrix\Calendar\Internals\EO_Event_Collection wakeUpCollection($rows)
 */
class EventTable extends Main\Entity\DataManager
{
	use DeleteByFilterTrait;
	use UpdateByFilterTrait;

	/**
	 * Returns DB table name for entity.
	 *
	 * @return string
	 */
	public static function getTableName()
	{
		return 'b_calendar_event';
	}

	/**
	 * Returns userfield entity code, to make userfields work with orm
	 *
	 * @return string
	 */
	public static function getUfId()
	{
		return Util::USER_FIELD_ENTITY_ID;
	}


	/**
	 * Returns entity map definition.
	 *
	 * @return array
	 * @throws Main\SystemException
	 */
	public static function getMap()
	{
		return [
			(new IntegerField('ID'))
				->configurePrimary(true)
				->configureAutocomplete(true)
			,
			(new IntegerField('PARENT_ID')),
			(new BooleanField('ACTIVE'))
				->configureValues('N', 'Y')
				->configureDefaultValue('Y')
			,
			(new BooleanField('DELETED'))
				->configureValues('N', 'Y')
				->configureDefaultValue('N')
			,
			(new StringField('CAL_TYPE',
				[
					'validation' => [__CLASS__, 'validateCalType']
				]
			)),
			(new IntegerField('OWNER_ID'))
				->configureRequired(true)
			,
			(new StringField('NAME',
				[
					'validation' => [__CLASS__, 'validateName']
				]
			)),
			(new DatetimeField('DATE_FROM')),
			(new DatetimeField('DATE_TO')),
			(new DatetimeField('ORIGINAL_DATE_FROM')),
			(new StringField('TZ_FROM',
				[
					'validation' => [__CLASS__, 'validateTzFrom']
				]
			)),
			(new StringField('TZ_TO',
				[
					'validation' => [__CLASS__, 'validateTzTo']
				]
			)),
			(new IntegerField('TZ_OFFSET_FROM')),
			(new IntegerField('TZ_OFFSET_TO')),
			(new IntegerField('DATE_FROM_TS_UTC')),
			(new IntegerField('DATE_TO_TS_UTC')),
			(new BooleanField('DT_SKIP_TIME'))
				->configureValues('N', 'Y')
				->configureDefaultValue('N')
			,
			(new IntegerField('DT_LENGTH')),
			(new StringField('EVENT_TYPE',
				[
					'validation' => [__CLASS__, 'validateEventType']
				]
			)),
			(new IntegerField('CREATED_BY'))
				->configureRequired(true)
			,
			(new DatetimeField('DATE_CREATE')),
			(new DatetimeField('TIMESTAMP_X')),
			(new TextField('DESCRIPTION')),
			(new DatetimeField('DT_FROM')),
			(new DatetimeField('DT_TO')),
			(new StringField('PRIVATE_EVENT',
				[
					'validation' => [__CLASS__, 'validatePrivateEvent']
				]
			)),
			(new StringField('ACCESSIBILITY',
				[
					'validation' => [__CLASS__, 'validateAccessibility']
				]
			)),
			(new StringField('IMPORTANCE',
				[
					'validation' => [__CLASS__, 'validateImportance']
				]
			)),
			(new StringField('IS_MEETING',
				[
					'validation' => [__CLASS__, 'validateIsMeeting']
				]
			)),
			(new StringField('MEETING_STATUS',
				[
					'validation' => [__CLASS__, 'validateMeetingStatus']
				]
			)),
			(new IntegerField('MEETING_HOST')),
			(new TextField('MEETING')),
			(new StringField('LOCATION',
				[
					'validation' => [__CLASS__, 'validateLocation']
				]
			)),
			(new TextField('REMIND',
				[
					'validation' => [__CLASS__, 'validateRemind']
				]
			)),
			(new StringField('COLOR',
				[
					'validation' => [__CLASS__, 'validateColor']
				]
			)),
			(new StringField('TEXT_COLOR',
				[
					'validation' => [__CLASS__, 'validateTextColor']
				]
			)),
			(new StringField('RRULE',
				[
					'validation' => [__CLASS__, 'validateRrule']
				]
			)),
			(new TextField('EXDATE',
				[]
			)),
			(new StringField('DAV_XML_ID',
				[
					'validation' => [__CLASS__, 'validateDavXmlId']
				]
			)),
			(new StringField('G_EVENT_ID',
				[
					'validation' => [__CLASS__, 'validateGEventId']
				]
			)),
			(new StringField('DAV_EXCH_LABEL',
				[
					'validation' => [__CLASS__, 'validateDavExchLabel']
				]
			)),
			(new StringField('CAL_DAV_LABEL',
				[
					'validation' => [__CLASS__, 'validateCalDavLabel']
				]
			)),
			(new StringField('VERSION',
				[
					'validation' => [__CLASS__, 'validateVersion']
				]
			)),
			(new TextField('ATTENDEES_CODES')),
			(new IntegerField('RECURRENCE_ID')),
			(new StringField('RELATIONS',
				[
					'validation' => [__CLASS__, 'validateRelations']
				]
			)),
			(new TextField('SEARCHABLE_CONTENT')),
			(new IntegerField('SECTION_ID')),
			(new StringField('SYNC_STATUS',
				[
					'validation' => [__CLASS__, 'validateSyncStatus']
				]
			)),
			(new ReferenceField(
				'SECTION',
				SectionTable::class,
				Join::on('this.SECTION_ID', 'ref.ID'),
			))
			,
			(new ReferenceField(
				'EVENT_SECT',
				EventSectTable::class,
				Join::on('this.ID', 'ref.EVENT_ID'),
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
	 * Returns validators for EVENT_TYPE field.
	 *
	 * @return array
	 */
	public static function validateEventType(): array
	{
		return [
			new LengthValidator(null, 50),
		];
	}

	/**
	 * Returns validators for PRIVATE_EVENT field.
	 *
	 * @return array
	 */
	public static function validatePrivateEvent(): array
	{
		return [
			new LengthValidator(null, 10),
		];
	}

	/**
	 * Returns validators for ACCESSIBILITY field.
	 *
	 * @return array
	 */
	public static function validateAccessibility(): array
	{
		return [
			new LengthValidator(null, 10),
		];
	}

	/**
	 * Returns validators for IMPORTANCE field.
	 *
	 * @return array
	 */
	public static function validateImportance(): array
	{
		return [
			new LengthValidator(null, 10),
		];
	}

	/**
	 * Returns validators for IS_MEETING field.
	 *
	 * @return array
	 */
	public static function validateIsMeeting(): array
	{
		return [
			new LengthValidator(null, 1),
		];
	}

	/**
	 * Returns validators for MEETING_STATUS field.
	 *
	 * @return array
	 */
	public static function validateMeetingStatus(): array
	{
		return [
			new LengthValidator(null, 1),
		];
	}

	/**
	 * Returns validators for LOCATION field.
	 *
	 * @return array
	 */
	public static function validateLocation(): array
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
	 * Returns validators for RRULE field.
	 *
	 * @return array
	 */
	public static function validateRrule(): array
	{
		return [
			new LengthValidator(null, 255),
		];
	}

	/**
	 * Returns validators for DAV_XML_ID field.
	 *
	 * @return array
	 */
	public static function validateDavXmlId(): array
	{
		return [
			new LengthValidator(null, 255),
		];
	}

	/**
	 * Returns validators for G_EVENT_ID field.
	 *
	 * @return array
	 */
	public static function validateGEventId(): array
	{
		return [
			new LengthValidator(null, 255),
		];
	}

	/**
	 * Returns validators for DAV_EXCH_LABEL field.
	 *
	 * @return array
	 */
	public static function validateDavExchLabel(): array
	{
		return [
			new LengthValidator(null, 255),
		];
	}

	/**
	 * Returns validators for CAL_DAV_LABEL field.
	 *
	 * @return array
	 */
	public static function validateCalDavLabel(): array
	{
		return [
			new LengthValidator(null, 255),
		];
	}

	/**
	 * Returns validators for VERSION field.
	 *
	 * @return array
	 */
	public static function validateVersion(): array
	{
		return [
			new LengthValidator(null, 255),
		];
	}

	/**
	 * Returns validators for RELATIONS field.
	 *
	 * @return array
	 */
	public static function validateRelations(): array
	{
		return [
			new LengthValidator(null, 255),
		];
	}

	/**
	 * Returns validators for SYNC_STATUS field.
	 *
	 * @return array
	 */
	public static function validateSyncStatus(): array
	{
		return [
			new LengthValidator(null, 20),
		];
	}

	/**
	 * Returns validators for REMIND field.
	 *
	 * @return array
	 */
	public static function validateRemind()
	{
		return array(
			new Main\Entity\Validator\Length(null, 255),
		);
	}
}
