<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Model;

use Bitrix\Disk\Internals\ObjectTable;
use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Data\Internal\DeleteByFilterTrait;
use Bitrix\Main\ORM\Fields\DatetimeField;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\ORM\Fields\Relations\Reference;
use Bitrix\Main\ORM\Query\Join;
use Bitrix\Main\Type\DateTime;

/**
 * Personal favorite mark of a Disk object: one row per user and object row of the list.
 *
 * DO NOT WRITE ANYTHING BELOW THIS
 *
 * <<< ORMENTITYANNOTATION
 * @method static EO_FavoriteObject_Query query()
 * @method static EO_FavoriteObject_Result getByPrimary($primary, array $parameters = [])
 * @method static EO_FavoriteObject_Result getById($id)
 * @method static EO_FavoriteObject_Result getList(array $parameters = [])
 * @method static EO_FavoriteObject_Entity getEntity()
 * @method static \Bitrix\Disk\Internal\Model\EO_FavoriteObject createObject($setDefaultValues = true)
 * @method static \Bitrix\Disk\Internal\Model\EO_FavoriteObject_Collection createCollection()
 * @method static \Bitrix\Disk\Internal\Model\EO_FavoriteObject wakeUpObject($row)
 * @method static \Bitrix\Disk\Internal\Model\EO_FavoriteObject_Collection wakeUpCollection($rows)
 */
final class FavoriteObjectTable extends DataManager
{
	use DeleteByFilterTrait;

	public static function getTableName(): string
	{
		return 'b_disk_favorite_object';
	}

	public static function getMap(): array
	{
		return [
			(new IntegerField('ID'))
				->configurePrimary()
				->configureAutocomplete()
			,
			(new IntegerField('USER_ID'))
				->configureRequired()
			,
			(new IntegerField('OBJECT_ID'))
				->configureRequired()
			,
			(new DatetimeField('CREATE_TIME'))
				->configureRequired()
				->configureDefaultValue(static fn (): DateTime => new DateTime())
			,
			(new Reference('OBJECT', ObjectTable::class, Join::on('this.OBJECT_ID', 'ref.ID')))
				->configureJoinType(Join::TYPE_INNER)
			,
		];
	}
}
