<?php

namespace Bitrix\Rest\Internal\Models;

use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\ORM\Fields\StringField;
use Bitrix\Main\ORM\Fields\Validators\LengthValidator;

/**
 * Class SystemUserTable
 *
 * DO NOT WRITE ANYTHING BELOW THIS
 *
 * <<< ORMENTITYANNOTATION
 * @method static EO_SystemUser_Query query()
 * @method static EO_SystemUser_Result getByPrimary($primary, array $parameters = [])
 * @method static EO_SystemUser_Result getById($id)
 * @method static EO_SystemUser_Result getList(array $parameters = [])
 * @method static EO_SystemUser_Entity getEntity()
 * @method static \Bitrix\Rest\Internal\Models\EO_SystemUser createObject($setDefaultValues = true)
 * @method static \Bitrix\Rest\Internal\Models\EO_SystemUser_Collection createCollection()
 * @method static \Bitrix\Rest\Internal\Models\EO_SystemUser wakeUpObject($row)
 * @method static \Bitrix\Rest\Internal\Models\EO_SystemUser_Collection wakeUpCollection($rows)
 */
final class SystemUserTable extends DataManager
{
	public static function getTableName()
	{
		return 'b_rest_system_user';
	}

	public static function getMap()
	{
		return [
			(new IntegerField('ID'))
				->configurePrimary()
				->configureAutocomplete()
				->configureSize(8),
			(new IntegerField('USER_ID'))
				->configureRequired(),
			(new StringField('ACCOUNT_TYPE'))
				->configureRequired()
				->addValidator(new LengthValidator(min:1, max:32)),
			(new StringField('RESOURCE_TYPE'))
				->configureRequired()
				->addValidator(new LengthValidator(min:1, max:32)),
			(new IntegerField('RESOURCE_ID'))
				->configureRequired(),
		];
	}
}