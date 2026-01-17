<?php

namespace Bitrix\DocumentGenerator\Model;

use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\ORM\Fields\Relations\CascadePolicy;
use Bitrix\Main\ORM\Fields\Relations\OneToMany;
use Bitrix\Main\ORM\Fields\StringField;

/**
 * Class RoleTable
 *
 * DO NOT WRITE ANYTHING BELOW THIS
 *
 * <<< ORMENTITYANNOTATION
 * @method static EO_Role_Query query()
 * @method static EO_Role_Result getByPrimary($primary, array $parameters = [])
 * @method static EO_Role_Result getById($id)
 * @method static EO_Role_Result getList(array $parameters = [])
 * @method static EO_Role_Entity getEntity()
 * @method static \Bitrix\DocumentGenerator\Model\Role createObject($setDefaultValues = true)
 * @method static \Bitrix\DocumentGenerator\Model\RoleCollection createCollection()
 * @method static \Bitrix\DocumentGenerator\Model\Role wakeUpObject($row)
 * @method static \Bitrix\DocumentGenerator\Model\RoleCollection wakeUpCollection($rows)
 */
class RoleTable extends DataManager
{
	public static function getTableName(): string
	{
		return 'b_documentgenerator_role';
	}

	public static function getMap(): array
	{
		return [
			(new IntegerField('ID'))
				->configurePrimary()
				->configureAutocomplete(),

			(new StringField('NAME'))
				->configureRequired(),

			(new StringField('CODE')),

			(new OneToMany(
				'PERMISSIONS',
				RolePermissionTable::class,
				'ROLE',
			))
				->configureCascadeDeletePolicy(CascadePolicy::FOLLOW),

			(new OneToMany(
				'ACCESSES',
				RoleAccessTable::class,
				'ROLE',
			))
				->configureCascadeDeletePolicy(CascadePolicy::FOLLOW),
		];
	}

	/**
	 * @return class-string<Role>
	 */
	public static function getObjectClass(): string
	{
		return Role::class;
	}

	/**
	 * @return class-string<RoleCollection>
	 */
	public static function getCollectionClass(): string
	{
		return RoleCollection::class;
	}
}
