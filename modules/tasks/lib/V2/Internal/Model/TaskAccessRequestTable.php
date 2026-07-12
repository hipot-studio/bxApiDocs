<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Model;

use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Data\Internal\DeleteByFilterTrait;
use Bitrix\Main\ORM\Fields\DatetimeField;
use Bitrix\Main\ORM\Fields\IntegerField;

/**
 * Class TaskAccessRequestTable
 *
 * DO NOT WRITE ANYTHING BELOW THIS
 *
 * <<< ORMENTITYANNOTATION
 * @method static EO_TaskAccessRequest_Query query()
 * @method static EO_TaskAccessRequest_Result getByPrimary($primary, array $parameters = [])
 * @method static EO_TaskAccessRequest_Result getById($id)
 * @method static EO_TaskAccessRequest_Result getList(array $parameters = [])
 * @method static EO_TaskAccessRequest_Entity getEntity()
 * @method static \Bitrix\Tasks\V2\Internal\Model\EO_TaskAccessRequest createObject($setDefaultValues = true)
 * @method static \Bitrix\Tasks\V2\Internal\Model\EO_TaskAccessRequest_Collection createCollection()
 * @method static \Bitrix\Tasks\V2\Internal\Model\EO_TaskAccessRequest wakeUpObject($row)
 * @method static \Bitrix\Tasks\V2\Internal\Model\EO_TaskAccessRequest_Collection wakeUpCollection($rows)
 */
class TaskAccessRequestTable extends DataManager
{
	use DeleteByFilterTrait;

	public static function getTableName(): string
	{
		return 'b_tasks_task_access_request';
	}

	public static function getMap(): array
	{
		return [
			(new IntegerField('TASK_ID'))
				->configurePrimary(),

			(new IntegerField('USER_ID'))
				->configurePrimary(),

			(new DatetimeField('CREATED_DATE'))
				->configureRequired(),
		];
	}
}
