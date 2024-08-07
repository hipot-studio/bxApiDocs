<?php

namespace Bitrix\ImOpenLines\Model;

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
 * @method static EO_RolePermission_Result getByPrimary($primary, array $parameters = array())
 * @method static EO_RolePermission_Result getById($id)
 * @method static EO_RolePermission_Result getList(array $parameters = array())
 * @method static EO_RolePermission_Entity getEntity()
 * @method static \Bitrix\ImOpenLines\Model\EO_RolePermission createObject($setDefaultValues = true)
 * @method static \Bitrix\ImOpenLines\Model\EO_RolePermission_Collection createCollection()
 * @method static \Bitrix\ImOpenLines\Model\EO_RolePermission wakeUpObject($row)
 * @method static \Bitrix\ImOpenLines\Model\EO_RolePermission_Collection wakeUpCollection($rows)
 */
class RolePermissionTable extends Entity\DataManager
{
	/**
	 * @inheritdoc
	 */
	public static function getTableName()
	{
		return 'b_imopenlines_role_permission';
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
				'Bitrix\ImOpenLines\Model\RoleAccess',
				array('=this.ROLE_ID' => 'ref.ROLE_ID'),
				array('join_type' => 'INNER')
			),
			'ROLE' => new Entity\ReferenceField(
				'ROLE',
				'Bitrix\ImOpenLines\Model\Role',
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
		{
			throw new ArgumentException('Role id should be greater than zero', 'roleId');
		}

		$connection = Application::getConnection();
		$connection->queryExecute("DELETE FROM ".self::getTableName()." WHERE ROLE_ID = ".$roleId);

		return new Entity\DeleteResult;
	}
}