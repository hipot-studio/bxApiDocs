<?php

namespace Bitrix\Voximplant\Model;

use Bitrix\Main\Application;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\Entity;

/**
 * Class RolePermissionTable
 *
 * DO NOT WRITE ANYTHING BELOW THIS
 *
 * <<< ORMENTITYANNOTATION
 * @method static EO_RolePermission_Query query()
 * @method static EO_RolePermission_Result getByPrimary($primary, array $parameters = [])
 * @method static EO_RolePermission_Result getById($id)
 * @method static EO_RolePermission_Result getList(array $parameters = [])
 * @method static EO_RolePermission_Entity getEntity()
 * @method static \Bitrix\Voximplant\Model\EO_RolePermission createObject($setDefaultValues = true)
 * @method static \Bitrix\Voximplant\Model\EO_RolePermission_Collection createCollection()
 * @method static \Bitrix\Voximplant\Model\EO_RolePermission wakeUpObject($row)
 * @method static \Bitrix\Voximplant\Model\EO_RolePermission_Collection wakeUpCollection($rows)
 */
class RolePermissionTable extends Base
{
	/**
	 * @inheritdoc
	 */
	public static function getTableName()
	{
		return 'b_voximplant_role_permission';
	}

	/**
	 * @inheritdoc
	 */
	public static function getMap()
	{
		return array(
			'ID' => new Entity\IntegerField('ID', array(
				'primary' => true,
				'autocomplete' => true,
			)),
			'ROLE_ID' => new Entity\IntegerField('ROLE_ID', array(
				'required' => true,
			)),
			'ENTITY' => new Entity\StringField('ENTITY', array(
				'required' => true,
			)),
			'ACTION' => new Entity\StringField('ACTION', array(
				'required' => true,
			)),
			'PERMISSION' => new Entity\StringField('PERMISSION'),
			'ROLE_ACCESS' => new Entity\ReferenceField(
				'ROLE_ACCESS',
				'Bitrix\Voximplant\Model\RoleAccess',
				array('=this.ROLE_ID' => 'ref.ROLE_ID'),
				array('join_type' => 'INNER')
			),
			'ROLE' => new Entity\ReferenceField(
				'ROLE',
				'Bitrix\Voximplant\Model\Role',
				array('=this.ROLE_ID' => 'ref.ID'),
				array('join_type' => 'INNER')
			),
		);
	}

	/**
	 * Deletes all permissions for the specified role.
	 * @param int $roleId Id of the role.
	 * @return Entity\DeleteResult
	 * @throws ArgumentException
	 */
	public static function deleteByRoleId($roleId)
	{
		$roleId = (int)$roleId;
		if($roleId <= 0)
			throw new ArgumentException('Role id should be greater than zero', 'roleId');

		$connection = Application::getConnection();
		$entity = self::getEntity();

		$sql = "DELETE FROM ".$entity->getDBTableName()." WHERE ROLE_ID = ".$roleId;
		$connection->queryExecute($sql);

		$result = new Entity\DeleteResult();
		return $result;
	}
}