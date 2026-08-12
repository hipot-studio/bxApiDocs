<?php
namespace Bitrix\Calendar\Internals;

use Bitrix\Main;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Fields\BooleanField;
use Bitrix\Main\ORM\Fields\DatetimeField;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\ORM\Fields\StringField;
use Bitrix\Main\ORM\Fields\EnumField;
use Bitrix\Main\ORM\Fields\Validators\LengthValidator;

Loc::loadMessages(__FILE__);

/**
 * Class PushTable
 *
 * Fields:
 * <ul>
 * <li> ENTITY_TYPE string(24) mandatory
 * <li> ENTITY_ID int mandatory
 * <li> CHANNEL_ID string(128) mandatory
 * <li> RESOURCE_ID string(128) mandatory
 * <li> EXPIRES datetime mandatory
 * <li> NOT_PROCESSED bool optional default 'N'
 * <li> FIRST_PUSH_DATE datetime optional
 * </ul>
 *
 * @deprecated
 *
 * @package Bitrix\Calendar\Internals
 *
 * DO NOT WRITE ANYTHING BELOW THIS
 *
 * <<< ORMENTITYANNOTATION
 * @method static EO_Push_Query query()
 * @method static EO_Push_Result getByPrimary($primary, array $parameters = [])
 * @method static EO_Push_Result getById($id)
 * @method static EO_Push_Result getList(array $parameters = [])
 * @method static EO_Push_Entity getEntity()
 * @method static \Bitrix\Calendar\Internals\EO_Push createObject($setDefaultValues = true)
 * @method static \Bitrix\Calendar\Internals\EO_Push_Collection createCollection()
 * @method static \Bitrix\Calendar\Internals\EO_Push wakeUpObject($row)
 * @method static \Bitrix\Calendar\Internals\EO_Push_Collection wakeUpCollection($rows)
 */

class PushTable extends Main\Entity\DataManager
{
	/**
	 * Returns DB table name for entity.
	 *
	 * @return string
	 */
	public static function getTableName()
	{
		return 'b_calendar_push';
	}

	/**
	 * Returns entity map definition.
	 *
	 * @return array
	 */
	public static function getMap()
	{
		return [
			(new StringField('ENTITY_TYPE',
				[
					'validation' => [__CLASS__, 'validateEntityType']
				]
			))
				->configurePrimary(true)
			,
			(new IntegerField('ENTITY_ID'))
				->configurePrimary(true)
			,
			(new StringField('CHANNEL_ID',
				[
					'validation' => [__CLASS__, 'validateChannelId']
				]
			))
				->configureRequired(true)
			,
			(new StringField('RESOURCE_ID',
				[
					'validation' => [__CLASS__, 'validateResourceId']
				]
			))
				->configureRequired(true)
			,
			(new DatetimeField('EXPIRES'))
				->configureRequired(true)
			,
			(new EnumField('NOT_PROCESSED'))
				->configureValues(['N', 'Y', 'B', 'U'])
				->configureDefaultValue('N')
			,
			(new DatetimeField('FIRST_PUSH_DATE')),
		];
	}

	/**
	 * Returns validators for ENTITY_TYPE field.
	 *
	 * @return array
	 */
	public static function validateEntityType(): array
	{
		return [
			new LengthValidator(null, 24),
		];
	}

	/**
	 * Returns validators for CHANNEL_ID field.
	 *
	 * @return array
	 */
	public static function validateChannelId(): array
	{
		return [
			new LengthValidator(null, 128),
		];
	}

	/**
	 * Returns validators for RESOURCE_ID field.
	 *
	 * @return array
	 */
	public static function validateResourceId(): array
	{
		return [
			new LengthValidator(null, 128),
		];
	}
}
