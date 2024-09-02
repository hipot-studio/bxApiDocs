<?php

namespace Bitrix\AI\Model;

use Bitrix\Main\Entity;
use Bitrix\Main\ORM\Data\Internal\DeleteByFilterTrait;
use Bitrix\Main\ORM\Fields\Relations\Reference;
use Bitrix\Main\ORM\Query\Join;

/**
 * Class RecentRoleTable
 *
 */
class RoleFavoriteTable extends Entity\DataManager
{
	use DeleteByFilterTrait;

	/**
	 * Returns DB table name for entity.
	 * @return string
	 */
	public static function getTableName(): string
	{
		return 'b_ai_role_favorite';
	}

	/**
	 * Returns entity map definition.
	 * @return array
	 */
	public static function getMap(): array
	{
		return [
			new Entity\IntegerField('ID', [
				'primary' => true,
				'autocomplete' => true,
			]),
			new Entity\StringField('ROLE_CODE', [
				'required' => true,
			]),
			(new Reference(
				'ROLE',
				RoleTable::class,
				Join::on('this.ROLE_CODE', 'ref.CODE')
			))->configureJoinType('inner'),
			new Entity\IntegerField('USER_ID', [
				'required' => true,
			]),
			new Entity\DatetimeField('DATE_CREATE'),
		];
	}
}
