<?php

namespace Bitrix\AI\Model;

use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Data\Internal\DeleteByFilterTrait;
use Bitrix\Main\ORM\Fields;

class MonthlyPoolReceiptTable extends DataManager
{
	use DeleteByFilterTrait;

	public static function getTableName(): string
	{
		return 'b_ai_monthly_pool_receipt';
	}

	public static function getMap(): array
	{
		return [
			(new Fields\StringField('ID'))->configurePrimary()->configureSize(36),
			(new Fields\IntegerField('USER_ID'))->configureRequired(),
			(new Fields\IntegerField('USAGE_ROW_ID'))->configureRequired(),
			(new Fields\StringField('PERIOD'))->configureRequired()->configureSize(50),
			(new Fields\IntegerField('COST'))->configureRequired(),
			(new Fields\StringField('STATE'))->configureRequired()->configureSize(16),
			(new Fields\DatetimeField('DATE_CREATE'))->configureRequired()->configureDefaultValueNow(),
		];
	}
}
