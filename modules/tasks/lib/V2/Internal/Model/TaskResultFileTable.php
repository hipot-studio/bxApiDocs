<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Model;

use Bitrix\Main\ORM\Data\AddStrategy\Trait\AddMergeTrait;
use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Data\Internal\DeleteByFilterTrait;
use Bitrix\Main\ORM\Fields\IntegerField;

class TaskResultFileTable extends DataManager
{
	use DeleteByFilterTrait;
	use AddMergeTrait;

	public static function getTableName(): string
	{
		return 'b_tasks_task_result_file';
	}

	public static function getMap(): array
	{
		return [
			(new IntegerField('ID'))
				->configurePrimary()
				->configureAutocomplete(),
			(new IntegerField('RESULT_ID'))
				->configureRequired(),
			(new IntegerField('FILE_ID'))
				->configureRequired(),
		];
	}
}
