<?php

namespace Bitrix\DocumentGenerator\Model;

use Bitrix\Main\Error;
use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Data\DeleteResult;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\ORM\Fields\Relations\Reference;
use Bitrix\Main\ORM\Fields\StringField;
use Bitrix\Main\ORM\Query\Join;

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
 * @method static \Bitrix\DocumentGenerator\Model\RolePermission createObject($setDefaultValues = true)
 * @method static \Bitrix\DocumentGenerator\Model\RolePermissionCollection createCollection()
 * @method static \Bitrix\DocumentGenerator\Model\RolePermission wakeUpObject($row)
 * @method static \Bitrix\DocumentGenerator\Model\RolePermissionCollection wakeUpCollection($rows)
 */
class RolePermissionTable extends DataManager
{
	public static function getTableName(): string
	{
		return 'b_documentgenerator_role_permission';
	}

	public static function getMap(): array
	{
		return [
			(new IntegerField('ID'))
				->configurePrimary()
				->configureAutocomplete(),

			(new IntegerField('ROLE_ID'))
				->configureRequired(),

			(new StringField('ENTITY'))
				->configureRequired(),

			(new StringField('ACTION'))
				->configureRequired(),

			(new StringField('PERMISSION'))
				->configureRequired(),

			new Reference(
				'ROLE_ACCESS',
				RoleAccess::class,
				Join::on('this.ROLE_ID', 'ref.ROLE_ID'),
				['join_type' => Join::TYPE_INNER],
			),

			new Reference(
				'ROLE',
				Role::class,
				Join::on('this.ROLE_ID', 'ref.ID'),
				['join_type' => Join::TYPE_INNER],
			),
		];
	}

	public static function deleteByRoleId(mixed $roleId): DeleteResult
	{
		$result = new DeleteResult();

		$roleId = (int)$roleId;
		if ($roleId <= 0)
		{
			return $result->addError(new Error('roleId should be more than zero'));
		}

		$rolePermissions = static::getList(['select' => ['ID'], 'filter' => ['ROLE_ID' => $roleId]]);
		while ($rolePermission = $rolePermissions->fetch())
		{
			$rolePermissionDeleteResult = static::delete($rolePermission['ID']);
			if (!$rolePermissionDeleteResult->isSuccess())
			{
				$result->addErrors($rolePermissionDeleteResult->getErrors());
			}
		}

		return $result;
	}

	/**
	 * @return class-string<RolePermission>
	 */
	public static function getObjectClass(): string
	{
		return RolePermission::class;
	}

	/**
	 * @return class-string<RolePermissionCollection>
	 */
	public static function getCollectionClass(): string
	{
		return RolePermissionCollection::class;
	}
}
