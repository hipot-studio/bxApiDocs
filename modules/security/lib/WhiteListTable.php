<?php

namespace Bitrix\Security;

use Bitrix\Main\ORM\Query\Query;
use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Fields;

/**
 * Class WhiteListTable
 *
 * DO NOT WRITE ANYTHING BELOW THIS
 *
 * <<< ORMENTITYANNOTATION
 * @method static EO_WhiteList_Query query()
 * @method static EO_WhiteList_Result getByPrimary($primary, array $parameters = [])
 * @method static EO_WhiteList_Result getById($id)
 * @method static EO_WhiteList_Result getList(array $parameters = [])
 * @method static EO_WhiteList_Entity getEntity()
 * @method static \Bitrix\Security\WhiteList createObject($setDefaultValues = true)
 * @method static \Bitrix\Security\WhiteLists createCollection()
 * @method static \Bitrix\Security\WhiteList wakeUpObject($row)
 * @method static \Bitrix\Security\WhiteLists wakeUpCollection($rows)
 */
class WhiteListTable extends DataManager
{
	public static function getTableName()
	{
		return 'b_sec_white_list';
	}

	public static function getMap()
	{
		return [
			(new Fields\IntegerField('ID'))
				->configurePrimary()
				->configureAutocomplete(),
			(new Fields\StringField('WHITE_SUBSTR'))
				->configureSize(250)
		];
	}

	public static function getCollectionClass()
	{
		return WhiteLists::class;
	}

	public static function getObjectClass()
	{
		return WhiteList::class;
	}

	public static function deleteList(array $filter)
	{
		$entity = static::getEntity();
		$connection = $entity->getConnection();

		$where = Query::buildFilterSql($entity, $filter);
		$where = $where ? 'WHERE ' . $where : '';

		$sql = sprintf(
			'DELETE FROM %s %s',
			$connection->getSqlHelper()->quote($entity->getDbTableName()),
			$where
		);

		$res = $connection->query($sql);

		return $res;
	}

}

class WhiteLists extends EO_WhiteList_Collection
{
}

class WhiteList extends EO_WhiteList
{
}
