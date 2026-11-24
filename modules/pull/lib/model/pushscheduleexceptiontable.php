<?php

declare(strict_types=1);

namespace Bitrix\Pull\Model;

use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\ORM\Fields\StringField;
use Bitrix\Main\ORM\Fields\Validators\LengthValidator;

final class PushScheduleExceptionTable extends DataManager
{
	public static function getTableName(): string
	{
		return 'b_pull_push_schedule_exception';
	}

	public static function getMap(): array
	{
		return [
			(new IntegerField('USER_ID'))
				->configurePrimary()
			,
			(new StringField('SOURCE_ID'))
				->configurePrimary()
				->configureSize(64)
				->addValidator(new LengthValidator(1, 64))
			,
		];
	}
}
