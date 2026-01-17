<?php

namespace Bitrix\DocumentGenerator\Model;

use Bitrix\Main\Application;
use Bitrix\Main\Error;
use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Data\DeleteResult;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\ORM\Fields\Relations\Reference;
use Bitrix\Main\ORM\Fields\StringField;
use Bitrix\Main\ORM\Query\Join;

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
 * @method static \Bitrix\DocumentGenerator\Model\RoleAccess createObject($setDefaultValues = true)
 * @method static \Bitrix\DocumentGenerator\Model\RoleAccessCollection createCollection()
 * @method static \Bitrix\DocumentGenerator\Model\RoleAccess wakeUpObject($row)
 * @method static \Bitrix\DocumentGenerator\Model\RoleAccessCollection wakeUpCollection($rows)
 */
class RoleAccessTable extends DataManager
{
	public static function getTableName(): string
	{
		return 'b_documentgenerator_role_access';
	}

	public static function getMap(): array
	{
		return [
			(new IntegerField('ID'))
				->configurePrimary()
				->configureAutocomplete(),

			(new IntegerField('ROLE_ID'))
				->configureRequired(),

			(new StringField('ACCESS_CODE'))
				->configureRequired(),

			new Reference(
				'ROLE',
				Role::class,
				Join::on('this.ROLE_ID',  'ref.ID'),
				['join_type' => Join::TYPE_INNER],
			),
		];
	}

	public static function truncate(): void
	{
		$connection = Application::getConnection();
		$connection->truncateTable(static::getTableName());
	}

	public static function deleteByRoleId(mixed $roleId): DeleteResult
	{
		$result = new DeleteResult();

		$roleId = (int)$roleId;
		if ($roleId <= 0)
		{
			return $result->addError(new Error('roleId should be more than zero'));
		}

		$roleAccessList = static::getList(['select' => ['ID'], 'filter' => ['ROLE_ID' => $roleId]]);
		while ($roleAccess = $roleAccessList->fetch())
		{
			$roleAccessDeleteResult = static::delete($roleAccess['ID']);
			if (!$roleAccessDeleteResult->isSuccess())
			{
				$result->addErrors($roleAccessDeleteResult->getErrors());
			}
		}

		return $result;
	}

	/**
	 * @return class-string<RoleAccess>
	 */
	public static function getObjectClass(): string
	{
		return RoleAccess::class;
	}

	/**
	 * @return class-string<RoleAccessCollection>
	 */
	public static function getCollectionClass(): string
	{
		return RoleAccessCollection::class;
	}
}
