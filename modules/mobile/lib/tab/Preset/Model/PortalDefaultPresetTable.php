<?php

namespace Bitrix\Mobile\Tab\Preset\Model;

use Bitrix\Main\ORM;
use Bitrix\Main\Type\DateTime;

class PortalDefaultPresetTable extends ORM\Data\DataManager
{
	public static function getTableName(): string
	{
		return 'b_mobile_tab_preset_portal_default';
	}

	public static function getMap(): array
	{
		return [
			(new ORM\Fields\StringField('SITE_ID'))
				->configurePrimary()
				->configureSize(2)
			,
			(new ORM\Fields\StringField('BUILTIN_PRESET_ID'))
				->configureRequired()
				->configureSize(64)
			,
			(new ORM\Fields\IntegerField('UPDATED_BY'))
				->configureRequired()
			,
			(new ORM\Fields\DatetimeField('UPDATED_AT'))
				->configureDefaultValue(static fn(): DateTime => new DateTime())
			,
		];
	}
}
