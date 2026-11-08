<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Integration\Sharepoint;

use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\ORM\Fields\StringField;

final class AllowedOriginTable extends DataManager
{
	public static function getTableName(): string
	{
		return 'b_intranet_sharepoint_allowed_origin';
	}

	public static function getMap(): array
	{
		return [
			(new IntegerField('ID'))
				->configurePrimary()
				->configureAutocomplete(),
			(new StringField('ORIGIN'))
				->configureRequired()
				->configureSize(512),
		];
	}
}
