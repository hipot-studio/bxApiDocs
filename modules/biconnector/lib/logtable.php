<?php
namespace Bitrix\BIConnector;

use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Fields\DatetimeField;
use Bitrix\Main\ORM\Fields\FloatField;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\ORM\Fields\Relations\Reference;
use Bitrix\Main\ORM\Fields\TextField;
use Bitrix\Main\ORM\Fields\StringField;
use Bitrix\Main\ORM\Fields\BooleanField;
use Bitrix\Main\ORM\Fields\Validators\LengthValidator;

/**
 * Class LogTable
 *
 * Fields:
 * <ul>
 * <li> ID int mandatory
 * <li> TIMESTAMP_X datetime mandatory
 * <li> KEY_ID int mandatory
 * <li> SERVICE_ID string(150) mandatory
 * <li> SOURCE_ID string(150) mandatory
 * <li> FIELDS text optional
 * <li> FILTERS text optional
 * <li> ROW_NUM int optional
 * <li> REAL_TIME double optional
 * <li> INPUT text optional
 * <li> REQUEST_METHOD string(15) optional
 * <li> REQUEST_URI string(2000) optional
 * <li> KEY_ID reference to {@link \Bitrix\BIConnector\BIConnectorKeyTable}
 * </ul>
 *
 * @package Bitrix\BIConnector
 *
 * DO NOT WRITE ANYTHING BELOW THIS
 *
 * <<< ORMENTITYANNOTATION
 * @method static EO_Log_Query query()
 * @method static EO_Log_Result getByPrimary($primary, array $parameters = [])
 * @method static EO_Log_Result getById($id)
 * @method static EO_Log_Result getList(array $parameters = [])
 * @method static EO_Log_Entity getEntity()
 * @method static \Bitrix\BIConnector\EO_Log createObject($setDefaultValues = true)
 * @method static \Bitrix\BIConnector\EO_Log_Collection createCollection()
 * @method static \Bitrix\BIConnector\EO_Log wakeUpObject($row)
 * @method static \Bitrix\BIConnector\EO_Log_Collection wakeUpCollection($rows)
 */

class LogTable extends DataManager
{
	/**
	 * Returns DB table name for entity.
	 *
	 * @return string
	 */
	public static function getTableName()
	{
		return 'b_biconnector_log';
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
				'TIMESTAMP_X',
				[
					'required' => true,
				]
			),
			new IntegerField(
				'KEY_ID',
				[
					'required' => true,
				]
			),
			new StringField(
				'SERVICE_ID',
				[
					'required' => true,
					'validation' => [__CLASS__, 'validateServiceId'],
				]
			),
			new StringField(
				'SOURCE_ID',
				[
					'required' => true,
					'validation' => [__CLASS__, 'validateSourceId'],
				]
			),
			new StringField(
				'FIELDS',
			),
			new StringField(
				'FILTERS',
			),
			new TextField(
				'INPUT',
			),
			new StringField(
				'REQUEST_METHOD',
				[
					'validation' => [__CLASS__, 'validateRequestMethod'],
				]
			),
			new StringField(
				'REQUEST_URI',
				[
					'validation' => [__CLASS__, 'validateRequestUri'],
				]
			),
			new IntegerField(
				'ROW_NUM',
			),
			new IntegerField(
				'DATA_SIZE',
			),
			new FloatField(
				'REAL_TIME',
			),
			new BooleanField(
				'IS_OVER_LIMIT',
				[
					'values' => ['N', 'Y'],
					'default' => 'N',
				]
			),
			new Reference(
				'KEY',
				'\Bitrix\BiConnector\KeyTable',
				['=this.KEY_ID' => 'ref.ID'],
				['join_type' => 'LEFT']
			),
		];
	}

	/**
	 * Returns validators for SOURCE_ID field.
	 *
	 * @return array
	 */
	public static function validateSourceId(): array
	{
		return [
			new LengthValidator(null, 150),
		];
	}

	/**
	 * Returns validators for SERVICE_ID field.
	 *
	 * @return array
	 */
	public static function validateServiceId(): array
	{
		return [
			new LengthValidator(null, 150),
		];
	}

	/**
	 * Returns validators for REQUEST_METHOD field.
	 *
	 * @return array
	 */
	public static function validateRequestMethod(): array
	{
		return [
			new LengthValidator(null, 15),
		];
	}

	/**
	 * Returns validators for REQUEST_URI field.
	 *
	 * @return array
	 */
	public static function validateRequestUri(): array
	{
		return [
			new LengthValidator(null, 2000),
		];
	}

	/**
	 * Deletes records with a direct query to the database by filter.
	 *
	 * @param  array $filter Delete condition.
	 * @return void
	 * @see \Bitrix\Main\ORM\Query\Query::buildFilterSql
	 */
	public static function deleteByFilter(array $filter)
	{
		$entity = static::getEntity();
		$sqlTableName = static::getTableName();

		$where = \Bitrix\Main\ORM\Query\Query::buildFilterSql($entity, $filter);
		if ($where)
		{
			$sql = 'DELETE FROM ' . $sqlTableName . ' WHERE ' . $where;
			$entity->getConnection()->queryExecute($sql);
		}
	}

	/**
	 * Agent deletes log records older than 30 days.
	 *
	 * @return string
	 */
	public static function cleanUpAgent()
	{
		$date = new \Bitrix\Main\Type\DateTime();
		$date->add('-30D');

		static::deleteByFilter([
			'<TIMESTAMP_X' => $date,
		]);

		return '\\Bitrix\\BIConnector\\LogTable::cleanUpAgent();';
	}
}
