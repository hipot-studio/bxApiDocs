<?php

namespace Bitrix\Rest\Internal\Model;

use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Fields\StringField;

class AppFreeWhitelistTable extends DataManager
{
	public static function getTableName(): string
	{
		return 'b_rest_free_app';
	}

	public static function getMap(): array
	{
		return [
			(new StringField('APP_CODE'))
				->configurePrimary(),
		];
	}
}
