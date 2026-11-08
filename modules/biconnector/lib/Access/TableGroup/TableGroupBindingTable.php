<?php

namespace Bitrix\BIConnector\Access\TableGroup;

use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Data\Internal\DeleteByFilterTrait;
use Bitrix\Main\ORM\Fields;
use Bitrix\Main\ORM\Fields\Relations\Reference;
use Bitrix\Main\ORM\Fields\Validators\LengthValidator;
use Bitrix\Main\ORM\Query\Join;

/**
 * Class TableGroupBindingTable
 *
 * DO NOT WRITE ANYTHING BELOW THIS
 *
 * <<< ORMENTITYANNOTATION
 * @method static EO_TableGroupBinding_Query query()
 * @method static EO_TableGroupBinding_Result getByPrimary($primary, array $parameters = [])
 * @method static EO_TableGroupBinding_Result getById($id)
 * @method static EO_TableGroupBinding_Result getList(array $parameters = [])
 * @method static EO_TableGroupBinding_Entity getEntity()
 * @method static \Bitrix\BIConnector\Access\TableGroup\EO_TableGroupBinding createObject($setDefaultValues = true)
 * @method static \Bitrix\BIConnector\Access\TableGroup\EO_TableGroupBinding_Collection createCollection()
 * @method static \Bitrix\BIConnector\Access\TableGroup\EO_TableGroupBinding wakeUpObject($row)
 * @method static \Bitrix\BIConnector\Access\TableGroup\EO_TableGroupBinding_Collection wakeUpCollection($rows)
 */
final class TableGroupBindingTable extends DataManager
{
	use DeleteByFilterTrait;

	public static function getTableName(): string
	{
		return 'b_biconnector_table_group_binding';
	}

	public static function getMap(): array
	{
		return [
			(new Fields\IntegerField('ID'))
				->configurePrimary()
				->configureAutocomplete()
			,

			(new Fields\IntegerField('GROUP_ID'))
				->configureRequired()
			,

			(new Reference(
				'GROUP',
				TableGroupTable::class,
				Join::on('this.GROUP_ID', 'ref.ID')
			))
				->configureJoinType(Join::TYPE_LEFT)
			,

			(new Fields\StringField('TABLE_CODE'))
				->configureRequired()
				->addValidator(new LengthValidator(null, 250))
			,
		];
	}
}
