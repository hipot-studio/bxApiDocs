<?php
namespace Bitrix\BIConnector;

use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Fields\DatetimeField;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\ORM\Fields\StringField;
use Bitrix\Main\ORM\Fields\BooleanField;
use Bitrix\Main\ORM\Fields\Relations\Reference;
use Bitrix\Main\ORM\Fields\Validators\LengthValidator;

/**
 * Class KeyTable
 *
 * Fields:
 * <ul>
 * <li> ID int mandatory
 * <li> TIMESTAMP_X datetime mandatory
 * <li> CREATED_BY int mandatory
 * <li> ACCESS_KEY string(64) mandatory
 * <li> CONNECTION string(50) mandatory
 * <li> ACTIVE bool optional default 'Y'
 * <li> APP_ID int optional
 * </ul>
 *
 * @package Bitrix\BIConnector
 *
 * DO NOT WRITE ANYTHING BELOW THIS
 *
 * <<< ORMENTITYANNOTATION
 * @method static EO_Key_Query query()
 * @method static EO_Key_Result getByPrimary($primary, array $parameters = [])
 * @method static EO_Key_Result getById($id)
 * @method static EO_Key_Result getList(array $parameters = [])
 * @method static EO_Key_Entity getEntity()
 * @method static \Bitrix\BIConnector\EO_Key createObject($setDefaultValues = true)
 * @method static \Bitrix\BIConnector\EO_Key_Collection createCollection()
 * @method static \Bitrix\BIConnector\EO_Key wakeUpObject($row)
 * @method static \Bitrix\BIConnector\EO_Key_Collection wakeUpCollection($rows)
 */

class KeyTable extends DataManager
{
	/**
	 * Returns DB table name for entity.
	 *
	 * @return string
	 */
	public static function getTableName()
	{
		return 'b_biconnector_key';
	}

	/**
	 * Returns entity map definition.
	 *
	 * @return array
	 */
	public static function getMap()
	{
		return [
			new IntegerField(
				'ID',
				[
					'primary' => true,
					'autocomplete' => true,
				]
			),
			new DatetimeField(
				'DATE_CREATE',
				[
					'required' => true,
				]
			),
			new DatetimeField(
				'TIMESTAMP_X',
				[
					'required' => true,
				]
			),
			new IntegerField(
				'CREATED_BY',
				[
					'required' => true,
				]
			),
			new StringField(
				'ACCESS_KEY',
				[
					'required' => true,
					'validation' => [__CLASS__, 'validateAccessKey'],
				]
			),
			new StringField(
				'CONNECTION',
				[
					'required' => true,
					'validation' => [__CLASS__, 'validateConnection'],
				]
			),
			new BooleanField(
				'ACTIVE',
				[
					'values' => ['N', 'Y'],
					'default' => 'Y',
				]
			),
			new IntegerField(
				'APP_ID',
			),
			new DatetimeField(
				'LAST_ACTIVITY_DATE',
			),
			new Reference(
				'PERMISSION',
				'\Bitrix\BIConnector\KeyUserTable',
				['=this.ID' => 'ref.KEY_ID'],
				['join_type' => 'INNER']
			),
			new Reference(
				'CREATED_USER',
				'\Bitrix\Main\UserTable',
				['=this.CREATED_BY' => 'ref.ID'],
				['join_type' => 'LEFT']
			),
			new StringField(
				'SERVICE_ID',
			),
			new Reference(
				'APPLICATION',
				'\Bitrix\Rest\AppTable',
				['=this.APP_ID' => 'ref.ID'],
				['join_type' => 'LEFT']
			),
		];
	}

	/**
	 * Returns validators for ACCESS_KEY field.
	 *
	 * @return array
	 */
	public static function validateAccessKey()
	{
		return [
			new LengthValidator(null, 64),
		];
	}

	/**
	 * Returns validators for CONNECTION field.
	 *
	 * @return array
	 */
	public static function validateConnection()
	{
		return [
			new LengthValidator(null, 50),
		];
	}
}
