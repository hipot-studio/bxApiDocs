<?php

namespace Bitrix\Mail\Internals\Access;

use Bitrix\Main;

/**
 * Class AccessRoleRelationTable
 *
 * DO NOT WRITE ANYTHING BELOW THIS
 *
 * <<< ORMENTITYANNOTATION
 * @method static EO_AccessRoleRelation_Query query()
 * @method static EO_AccessRoleRelation_Result getByPrimary($primary, array $parameters = [])
 * @method static EO_AccessRoleRelation_Result getById($id)
 * @method static EO_AccessRoleRelation_Result getList(array $parameters = [])
 * @method static EO_AccessRoleRelation_Entity getEntity()
 * @method static \Bitrix\Mail\Internals\Access\EO_AccessRoleRelation createObject($setDefaultValues = true)
 * @method static \Bitrix\Mail\Internals\Access\EO_AccessRoleRelation_Collection createCollection()
 * @method static \Bitrix\Mail\Internals\Access\EO_AccessRoleRelation wakeUpObject($row)
 * @method static \Bitrix\Mail\Internals\Access\EO_AccessRoleRelation_Collection wakeUpCollection($rows)
 */
class AccessRoleRelationTable extends Main\Access\Role\AccessRoleRelationTable
{
	public static function getTableName(): string
	{
		return 'b_mail_access_role_relation';
	}
}