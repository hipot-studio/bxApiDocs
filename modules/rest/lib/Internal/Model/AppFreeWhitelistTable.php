<?php

namespace Bitrix\Rest\Internal\Model;

use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Fields\StringField;

/**
 * Class AppFreeWhitelistTable
 *
 * DO NOT WRITE ANYTHING BELOW THIS
 *
 * <<< ORMENTITYANNOTATION
 * @method static EO_AppFreeWhitelist_Query query()
 * @method static EO_AppFreeWhitelist_Result getByPrimary($primary, array $parameters = [])
 * @method static EO_AppFreeWhitelist_Result getById($id)
 * @method static EO_AppFreeWhitelist_Result getList(array $parameters = [])
 * @method static EO_AppFreeWhitelist_Entity getEntity()
 * @method static \Bitrix\Rest\Internal\Model\EO_AppFreeWhitelist createObject($setDefaultValues = true)
 * @method static \Bitrix\Rest\Internal\Model\EO_AppFreeWhitelist_Collection createCollection()
 * @method static \Bitrix\Rest\Internal\Model\EO_AppFreeWhitelist wakeUpObject($row)
 * @method static \Bitrix\Rest\Internal\Model\EO_AppFreeWhitelist_Collection wakeUpCollection($rows)
 */
class AppFreeWhitelistTable extends DataManager
{
	public static function getTableName(): string
	{
		return 'b_rest_free_app';
	}

	public static function getMap(): array
	{
		return [
			(new StringField('APP_CODE'))
				->configurePrimary(),
		];
	}
}
