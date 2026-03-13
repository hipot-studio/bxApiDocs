<?php

namespace Bitrix\HumanResources\Model;

use Bitrix\HumanResources\Internals\Service\Container as InternalContainer;
use Bitrix\HumanResources\Type\MemberEntityType;
use Bitrix\HumanResources\Type\UserSettingsType;
use Bitrix\Main\ORM;
use Bitrix\Main\Type\DateTime;

/**
 * Class UserSettingsTable
 *
 * DO NOT WRITE ANYTHING BELOW THIS
 *
 * <<< ORMENTITYANNOTATION
 * @method static EO_UserSettings_Query query()
 * @method static EO_UserSettings_Result getByPrimary($primary, array $parameters = [])
 * @method static EO_UserSettings_Result getById($id)
 * @method static EO_UserSettings_Result getList(array $parameters = [])
 * @method static EO_UserSettings_Entity getEntity()
 * @method static \Bitrix\HumanResources\Model\UserSettings createObject($setDefaultValues = true)
 * @method static \Bitrix\HumanResources\Model\UserSettingsCollection createCollection()
 * @method static \Bitrix\HumanResources\Model\UserSettings wakeUpObject($row)
 * @method static \Bitrix\HumanResources\Model\UserSettingsCollection wakeUpCollection($rows)
 */
class UserSettingsTable extends ORM\Data\DataManager
{
	use ORM\Data\Internal\DeleteByFilterTrait;

	public static function getObjectClass(): string
	{
		return UserSettings::class;
	}

	public static function getCollectionClass(): string
	{
		return UserSettingsCollection::class;
	}

	public static function getTableName(): string
	{
		return 'b_hr_structure_user_settings';
	}

	public static function getMap(): array
	{
		return [
			(new ORM\Fields\IntegerField('ID'))
				->configurePrimary()
				->configureAutocomplete()
				->configureTitle('ID')
			,
			(new ORM\Fields\IntegerField('USER_ID'))
				->configureTitle('User id')
			,
			(new ORM\Fields\EnumField('SETTINGS_TYPE'))
				->configureValues(UserSettingsType::values())
				->configureTitle('Settings type')
			,
			(new ORM\Fields\StringField('SETTINGS_VALUE'))
				->configureTitle('Value')
			,
			(new ORM\Fields\DatetimeField('CREATED_AT'))
				->configureDefaultValue(new DateTime())
				->configureTitle('CREATED_AT')
			,
			(new ORM\Fields\DatetimeField('UPDATED_AT'))
				->configureDefaultValue(new DateTime())
				->configureTitle('UPDATED_AT')
			,
		];
	}

	public static function deleteByNodeMembers(array $nodeMembers)
	{
		foreach ($nodeMembers as $nodeMember)
		{
			if (isset($nodeMember['ENTITY_ID'])
				&& isset($nodeMember['NODE_ID'])
				&& isset($nodeMember['ENTITY_TYPE'])
				&& $nodeMember['ENTITY_TYPE'] === MemberEntityType::USER->value
			)
			{
				InternalContainer::getUserSettingsRepository()->removeByUserIdAndNodeId(
					(int)$nodeMember['ENTITY_ID'],
					(int)$nodeMember['NODE_ID'],
				);
			}
		}
	}

	public static function deleteListByStructureId(int $structureId): void
	{
		$entity = static::getEntity();
		$connection = $entity->getConnection();
		$tableName = $connection->getSqlHelper()
			->quote($entity->getDbTableName())
		;
		$nodeTableName = $connection->getSqlHelper()
			->quote(NodeTable::getTableName())
		;
		$settingNames = UserSettingsType::values();

		if (empty($settingNames))
		{
			return;
		}

		$settingNamesString = implode("','", $settingNames);

		if ($connection->getType() === 'mysql')
		{
			$sql = "
				DELETE $tableName FROM $tableName 
				INNER JOIN $nodeTableName ON $tableName.SETTINGS_VALUE = $nodeTableName.ID 
				WHERE $nodeTableName.STRUCTURE_ID = $structureId
				AND $tableName.SETTINGS_TYPE IN ('$settingNamesString')
			";

			$connection->query($sql);

			return;
		}

		$sql = "
			DELETE FROM $tableName WHERE SETTINGS_VALUE IN (
				SELECT ID FROM $nodeTableName WHERE STRUCTURE_ID = $structureId)
			AND $tableName.SETTINGS_TYPE IN ('$settingNamesString');
		";

		$connection->query($sql);
	}
}
