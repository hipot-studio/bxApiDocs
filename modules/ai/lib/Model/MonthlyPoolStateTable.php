<?php

namespace Bitrix\AI\Model;

use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Fields;

class MonthlyPoolStateTable extends DataManager
{
	public static function getTableName(): string
	{
		return 'b_ai_monthly_pool_state';
	}

	public static function getMap(): array
	{
		return [
			(new Fields\IntegerField('ID'))->configurePrimary(),
			(new Fields\StringField('MODE'))->configureRequired()->configureSize(16)->configureDefaultValue('legacy'),
			(new Fields\IntegerField('EPOCH'))->configureRequired()->configureDefaultValue(0),
			(new Fields\StringField('CUTOVER_REF'))->configureSize(255),
		];
	}
}
