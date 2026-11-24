<?php

declare(strict_types=1);

namespace Bitrix\Pull\Model;

use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Fields\DatetimeField;
use Bitrix\Main\ORM\Fields\IntegerField;

final class PushScheduleVacationClaimTable extends DataManager
{
	public static function getTableName(): string
	{
		return 'b_pull_push_schedule_vacation_claim';
	}

	public static function getMap(): array
	{
		return [
			(new IntegerField('USER_ID'))
				->configurePrimary()
			,
			(new IntegerField('ABSENCE_ID'))
				->configurePrimary()
			,
			(new IntegerField('PROTOCOL_VERSION'))
				->configureRequired()
				->configureDefaultValue(1)
			,
			(new DatetimeField('CLAIMED_AT'))
				->configureRequired()
				->configureDefaultValueNow()
			,
			(new DatetimeField('CONFIRMED_AT')),
		];
	}
}
