<?php

namespace Bitrix\Call\Model;

use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Data\Internal\DeleteByFilterTrait;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\ORM\Fields\Relations\Reference;
use Bitrix\Main\ORM\Query\Join;

/**
 * Class CallUserLogCountersTable
 *
 * DO NOT WRITE ANYTHING BELOW THIS
 *
 * <<< ORMENTITYANNOTATION
 * @method static EO_CallUserLogCounters_Query query()
 * @method static EO_CallUserLogCounters_Result getByPrimary($primary, array $parameters = [])
 * @method static EO_CallUserLogCounters_Result getById($id)
 * @method static EO_CallUserLogCounters_Result getList(array $parameters = [])
 * @method static EO_CallUserLogCounters_Entity getEntity()
 * @method static \Bitrix\Call\Model\EO_CallUserLogCounters createObject($setDefaultValues = true)
 * @method static \Bitrix\Call\Model\EO_CallUserLogCounters_Collection createCollection()
 * @method static \Bitrix\Call\Model\EO_CallUserLogCounters wakeUpObject($row)
 * @method static \Bitrix\Call\Model\EO_CallUserLogCounters_Collection wakeUpCollection($rows)
 */
class CallUserLogCountersTable extends DataManager
{
	use DeleteByFilterTrait;
	public static function getTableName(): string
	{
		return 'b_call_userlog_counters';
	}

	public static function getMap(): array
	{
		return [
			(new IntegerField('ID'))
				->configurePrimary()
				->configureAutocomplete(),

			(new IntegerField('USERLOG_ID'))
				->configureRequired(),

			(new IntegerField('USER_ID'))
				->configureRequired(),

			// Relation to CallUserLog table
			(new Reference(
				'USERLOG',
				CallUserLogTable::class,
				Join::on('this.USERLOG_ID', 'ref.ID')
			))->configureJoinType(Join::TYPE_INNER),
		];
	}
}
