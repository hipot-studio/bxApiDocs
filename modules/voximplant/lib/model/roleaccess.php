<?php

namespace Bitrix\Voximplant\Model;

use Bitrix\Main\ArgumentException;
use Bitrix\Main\Application;
use Bitrix\Main\Entity;

/**
 * Class RoleAccessTable
 *
 * DO NOT WRITE ANYTHING BELOW THIS
 *
 * <<< ORMENTITYANNOTATION
 * @method static EO_RoleAccess_Query query()
 * @method static EO_RoleAccess_Result getByPrimary($primary, array $parameters = [])
 * @method static EO_RoleAccess_Result getById($id)
 * @method static EO_RoleAccess_Result getList(array $parameters = [])
 * @method static EO_RoleAccess_Entity getEntity()
 * @method static \Bitrix\Voximplant\Model\EO_RoleAccess createObject($setDefaultValues = true)
 * @method static \Bitrix\Voximplant\Model\EO_RoleAccess_Collection createCollection()
 * @method static \Bitrix\Voximplant\Model\EO_RoleAccess wakeUpObject($row)
 * @method static \Bitrix\Voximplant\Model\EO_RoleAccess_Collection wakeUpCollection($rows)
 */
class RoleAccessTable extends Base
{
	/**
	 * @inheritdoc
	 */
	public static function getTableName()
	{
		return 'b_voximplant_role_access';
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
			'ACCESS_CODE' => new Entity\StringField('ACCESS_CODE', array(
				'required' => true,
			)),
			'ROLE' => new Entity\ReferenceField(
				'ROLE',
				'Bitrix\Voximplant\Model\Role',
				array('=this.ROLE_ID' => 'ref.ID'),
				array('join_type' => 'INNER')
			)
		);
	}

	/**
	 * Deletes all records from the table
	 * @return Entity\DeleteResult
	 */
	public static function truncate()
	{
		Application::getConnection()->truncateTable(self::getTableName());

		return new Entity\DeleteResult();
	}

	/**
	 * Deletes all access codes associated with the specified role.
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