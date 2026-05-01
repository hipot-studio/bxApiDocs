<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Model;

use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Data\Internal\DeleteByFilterTrait;
use Bitrix\Main\ORM\Fields\DatetimeField;
use Bitrix\Main\ORM\Fields\IntegerField;

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
