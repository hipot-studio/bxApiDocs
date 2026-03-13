<?php
namespace Bitrix\BIConnector;

use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Fields\DatetimeField;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\ORM\Fields\StringField;
use Bitrix\Main\ORM\Fields\Relations\Reference;
use Bitrix\Main\ORM\Fields\Validators\LengthValidator;

/**
 * Class DashboardTable
 *
 * Fields:
 * <ul>
 * <li> ID int mandatory
 * <li> TIMESTAMP_X datetime mandatory
 * <li> CREATED_BY int mandatory
 * <li> NAME string(50) mandatory
 * <li> URL string(1024) mandatory
 * </ul>
 *
 * @package Bitrix\BIConnector
 *
 * DO NOT WRITE ANYTHING BELOW THIS
 *
 * <<< ORMENTITYANNOTATION
 * @method static EO_Dashboard_Query query()
 * @method static EO_Dashboard_Result getByPrimary($primary, array $parameters = [])
 * @method static EO_Dashboard_Result getById($id)
 * @method static EO_Dashboard_Result getList(array $parameters = [])
 * @method static EO_Dashboard_Entity getEntity()
 * @method static \Bitrix\BIConnector\EO_Dashboard createObject($setDefaultValues = true)
 * @method static \Bitrix\BIConnector\EO_Dashboard_Collection createCollection()
 * @method static \Bitrix\BIConnector\EO_Dashboard wakeUpObject($row)
 * @method static \Bitrix\BIConnector\EO_Dashboard_Collection wakeUpCollection($rows)
 */

class DashboardTable extends DataManager
{
	const MAX_NAME_LENGTH = 50;
	const MAX_URL_LENGTH = 1024;

	/**
	 * Returns DB table name for entity.
	 *
	 * @return string
	 */
	public static function getTableName()
	{
		return 'b_biconnector_dashboard';
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
				'DATE_LAST_VIEW',
				[
					'required' => false,
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
			new IntegerField(
				'LAST_VIEW_BY',
			),
			new StringField(
				'NAME',
				[
					'required' => true,
					'validation' => [__CLASS__, 'validateName'],
				]
			),
			new StringField(
				'URL',
				[
					'required' => true,
					'validation' => [__CLASS__, 'validateUrl'],
				]
			),
			new Reference(
				'PERMISSION',
				'\Bitrix\BIConnector\DashboardUserTable',
				['=this.ID' => 'ref.DASHBOARD_ID'],
				['join_type' => 'INNER']
			),
			new Reference(
				'CREATED_USER',
				'\Bitrix\Main\UserTable',
				['=this.CREATED_BY' => 'ref.ID'],
				['join_type' => 'LEFT']
			),
			new Reference(
				'LAST_VIEW_USER',
				'\Bitrix\Main\UserTable',
				['=this.LAST_VIEW_BY' => 'ref.ID'],
				['join_type' => 'LEFT']
			),
		];
	}

	/**
	 * Returns validators for NAME field.
	 *
	 * @return array
	 */
	public static function validateName()
	{
		return [
			new LengthValidator(null, static::MAX_NAME_LENGTH),
		];
	}

	/**
	 * Returns validators for URL field.
	 *
	 * @return array
	 */
	public static function validateUrl()
	{
		return [
			new LengthValidator(null, static::MAX_URL_LENGTH),
		];
	}
}
