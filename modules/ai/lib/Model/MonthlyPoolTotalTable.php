<?php

namespace Bitrix\AI\Model;

use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Fields;

class MonthlyPoolTotalTable extends DataManager
{
	public static function getTableName(): string
	{
		return 'b_ai_monthly_pool_total';
	}

	public static function getMap(): array
	{
		return [
			(new Fields\StringField('PERIOD'))->configurePrimary()->configureSize(50),
			(new Fields\IntegerField('TOTAL'))->configureRequired()->configureDefaultValue(0),
			(new Fields\BooleanField('READY'))->configureValues('N', 'Y')->configureDefaultValue('N'),
		];
	}
}
