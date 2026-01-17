<?php

namespace Bitrix\Security;

use Bitrix\Main\ORM\Query\Query;
use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Fields;

/**
 * Class RedirectRuleTable
 *
 * DO NOT WRITE ANYTHING BELOW THIS
 *
 * <<< ORMENTITYANNOTATION
 * @method static EO_RedirectRule_Query query()
 * @method static EO_RedirectRule_Result getByPrimary($primary, array $parameters = [])
 * @method static EO_RedirectRule_Result getById($id)
 * @method static EO_RedirectRule_Result getList(array $parameters = [])
 * @method static EO_RedirectRule_Entity getEntity()
 * @method static \Bitrix\Security\RedirectRule createObject($setDefaultValues = true)
 * @method static \Bitrix\Security\RedirectRules createCollection()
 * @method static \Bitrix\Security\RedirectRule wakeUpObject($row)
 * @method static \Bitrix\Security\RedirectRules wakeUpCollection($rows)
 */
class RedirectRuleTable extends DataManager
{
	public static function getTableName()
	{
		return 'b_sec_redirect_url';
	}

	public static function getMap()
	{
		return [
			(new Fields\EnumField('IS_SYSTEM'))
				->configureValues(['Y', 'N'])
				->configureDefaultValue('Y'),
			(new Fields\IntegerField('SORT'))
				->configureDefaultValue(500),
			(new Fields\StringField('URL'))
				->configurePrimary()
				->configureSize(250),
			(new Fields\StringField('PARAMETER_NAME'))
				->configurePrimary()
				->configureSize(250)
		];
	}

	public static function getCollectionClass()
	{
		return RedirectRules::class;
	}

	public static function getObjectClass()
	{
		return RedirectRule::class;
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

class RedirectRules extends EO_RedirectRule_Collection
{
}

class RedirectRule extends EO_RedirectRule
{
}
