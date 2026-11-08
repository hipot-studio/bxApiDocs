<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Model;

use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Data\Internal\DeleteByFilterTrait;
use Bitrix\Main\ORM\Fields\DatetimeField;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\ORM\Fields\StringField;

/**
 * Class InteractionLogTable
 *
 * DO NOT WRITE ANYTHING BELOW THIS
 *
 * <<< ORMENTITYANNOTATION
 * @method static EO_InteractionLog_Query query()
 * @method static EO_InteractionLog_Result getByPrimary($primary, array $parameters = [])
 * @method static EO_InteractionLog_Result getById($id)
 * @method static EO_InteractionLog_Result getList(array $parameters = [])
 * @method static EO_InteractionLog_Entity getEntity()
 * @method static \Bitrix\Tasks\V2\Internal\Model\EO_InteractionLog createObject($setDefaultValues = true)
 * @method static \Bitrix\Tasks\V2\Internal\Model\EO_InteractionLog_Collection createCollection()
 * @method static \Bitrix\Tasks\V2\Internal\Model\EO_InteractionLog wakeUpObject($row)
 * @method static \Bitrix\Tasks\V2\Internal\Model\EO_InteractionLog_Collection wakeUpCollection($rows)
 */
class InteractionLogTable extends DataManager
{
	use DeleteByFilterTrait;

	public static function getTableName(): string
	{
		return 'b_tasks_interaction_log';
	}

	public static function getMap(): array
	{
		return [
			// 8 bytes to match the BIGINT column: the default size of 4 would clamp
			// every filter and delete by ID to 2147483647.
			(new IntegerField('ID'))
				->configureSize(8)
				->configurePrimary()
				->configureAutocomplete(),

			(new IntegerField('USER_ID'))
				->configureRequired(),

			(new IntegerField('TASK_ID'))
				->configureRequired(),

			(new StringField('EVENT_TYPE'))
				->configureRequired()
				->configureSize(16),

			(new StringField('RELATION_KIND'))
				->configureNullable()
				->configureSize(16),

			(new DatetimeField('CREATED_AT'))
				->configureRequired(),
		];
	}
}
