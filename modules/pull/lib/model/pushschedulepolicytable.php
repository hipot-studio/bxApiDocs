<?php

declare(strict_types=1);

namespace Bitrix\Pull\Model;

use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Fields\BooleanField;
use Bitrix\Main\ORM\Fields\DatetimeField;
use Bitrix\Main\ORM\Fields\IntegerField;

final class PushSchedulePolicyTable extends DataManager
{
	public static function getTableName(): string
	{
		return 'b_pull_push_schedule';
	}

	public static function getMap(): array
	{
		return [
			(new IntegerField('USER_ID'))
				->configurePrimary()
			,
			(new BooleanField('AUTO_ENABLED'))
				->configureRequired()
				->configureValues('N', 'Y')
				->configureDefaultValue(false)
			,
			(new IntegerField('REVISION'))
				->configureRequired()
				->configureDefaultValue(1)
				->addValidator(static fn (int $value): true|string => $value >= 1 ? true : 'Revision must be positive.')
			,
			(new DatetimeField('UPDATED_AT'))
				->configureRequired()
				->configureDefaultValueNow()
			,
		];
	}
}
