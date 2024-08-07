<?php
namespace Bitrix\Crm\Cleaning\Entity;

use Bitrix\Main;

/**
 * Class CleaningTable
 *
 * DO NOT WRITE ANYTHING BELOW THIS
 *
 * <<< ORMENTITYANNOTATION
 * @method static EO_Cleaning_Query query()
 * @method static EO_Cleaning_Result getByPrimary($primary, array $parameters = [])
 * @method static EO_Cleaning_Result getById($id)
 * @method static EO_Cleaning_Result getList(array $parameters = [])
 * @method static EO_Cleaning_Entity getEntity()
 * @method static \Bitrix\Crm\Cleaning\Entity\EO_Cleaning createObject($setDefaultValues = true)
 * @method static \Bitrix\Crm\Cleaning\Entity\EO_Cleaning_Collection createCollection()
 * @method static \Bitrix\Crm\Cleaning\Entity\EO_Cleaning wakeUpObject($row)
 * @method static \Bitrix\Crm\Cleaning\Entity\EO_Cleaning_Collection wakeUpCollection($rows)
 */
class CleaningTable extends Main\ORM\Data\DataManager
{
	/**
	 * Returns DB table name for entity
	 * @return string
	 */
	public static function getTableName()
	{
		return 'b_crm_cleaning';
	}

	/**
	 * Returns entity map definition.
	 * @return array
	 */
	public static function getMap(): array
	{
		return [
			'ENTITY_TYPE_ID' => ['data_type' => 'integer', 'primary' => true],
			'ENTITY_ID' => ['data_type' => 'integer', 'primary' => true],
			'CREATED_TIME' => ['data_type' => 'datetime', 'required' => true],
			'LAST_UPDATED_TIME' => ['data_type' => 'datetime', 'required' => true],
			'FORCE_USER_ID' => ['data_type' => 'integer', 'required' => false],
		];
	}

	public static function upsert(array $data)
	{
		$entityTypeID = isset($data['ENTITY_TYPE_ID']) ? (int)$data['ENTITY_TYPE_ID'] : \CCrmOwnerType::Undefined;
		$entityID = isset($data['ENTITY_ID']) ? (int)$data['ENTITY_ID'] : 0;
		$forceUserId = $data['FORCE_USER_ID'] ?? null;

		$now = new Main\Type\DateTime();

		$connection = Main\Application::getConnection();
		$queries = $connection->getSqlHelper()->prepareMerge(
			'b_crm_cleaning',
			['ENTITY_TYPE_ID', 'ENTITY_ID'],
			[
				'ENTITY_TYPE_ID' => $entityTypeID,
				'ENTITY_ID' => $entityID,
				'CREATED_TIME' => $now,
				'LAST_UPDATED_TIME' => $now,
				'FORCE_USER_ID' => $forceUserId,
			],
			['LAST_UPDATED_TIME' => $now]
		);

		foreach($queries as $query)
		{
			$connection->queryExecute($query);
		}
	}

	public static function deleteByFilter(array $filter)
	{
		$connection = Main\Application::getConnection();
		$helper = $connection->getSqlHelper();
		$tableName = self::getTableName();

		$conditions = array();
		foreach($filter as $k => $v)
		{
			$conditions[] = $helper->prepareAssignment($tableName, $k, $v);
		}
		$connection->queryExecute('DELETE FROM '.$tableName.' WHERE '.implode(' AND ', $conditions));
	}
}