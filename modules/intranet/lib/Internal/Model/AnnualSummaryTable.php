<?php

namespace Bitrix\Intranet\Internal\Model;

use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\ORM\Fields\StringField;
use Bitrix\Main\ORM\Fields\TextField;

class AnnualSummaryTable extends DataManager
{
	public static function getTableName(): string
	{
		return 'b_intranet_user_annual_summary';
	}

	public static function getMap(): array
	{
		return [
			(new IntegerField('ID'))
				->configurePrimary()
				->configureAutocomplete(),
			(new IntegerField('USER_ID'))
				->configureRequired(),
			(new StringField('NAME'))
				->configureRequired(),
			(new TextField('VALUE'))
				->configureNullable(),
		];
	}
}