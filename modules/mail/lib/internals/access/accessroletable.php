<?php

namespace Bitrix\Mail\Internals\Access;

use Bitrix\Main;


/**
 * Class AccessRoleTable
 *
 * DO NOT WRITE ANYTHING BELOW THIS
 *
 * <<< ORMENTITYANNOTATION
 * @method static EO_AccessRole_Query query()
 * @method static EO_AccessRole_Result getByPrimary($primary, array $parameters = [])
 * @method static EO_AccessRole_Result getById($id)
 * @method static EO_AccessRole_Result getList(array $parameters = [])
 * @method static EO_AccessRole_Entity getEntity()
 * @method static \Bitrix\Mail\Internals\Access\EO_AccessRole createObject($setDefaultValues = true)
 * @method static \Bitrix\Mail\Internals\Access\EO_AccessRole_Collection createCollection()
 * @method static \Bitrix\Mail\Internals\Access\EO_AccessRole wakeUpObject($row)
 * @method static \Bitrix\Mail\Internals\Access\EO_AccessRole_Collection wakeUpCollection($rows)
 */
class AccessRoleTable extends Main\Access\Role\AccessRoleTable
{
	public static function getTableName(): string
	{
		return 'b_mail_access_role';
	}
}