<?php

namespace Bitrix\Mobile\Tab\Preset\Model;

use Bitrix\Main\ORM;
use Bitrix\Main\Type\DateTime;
use Bitrix\Mobile\Tab\Preset\Enum\PresetSource;

class UserPresetStateTable extends ORM\Data\DataManager
{
	public static function getTableName(): string
	{
		return 'b_mobile_tab_preset_user_state';
	}

	public static function getMap(): array
	{
		return [
			(new ORM\Fields\IntegerField('USER_ID'))
				->configurePrimary()
			,
			(new ORM\Fields\StringField('SITE_ID'))
				->configurePrimary()
				->configureSize(2)
			,
			(new ORM\Fields\EnumField('CURRENT_SOURCE'))
				->configureRequired()
				->configureValues(PresetSource::values())
			,
			(new ORM\Fields\IntegerField('CURRENT_PRESET_ID')),
			(new ORM\Fields\StringField('CURRENT_BUILTIN_ID'))
				->configureSize(64)
			,
			(new ORM\Fields\TextField('APPLIED_CONFIG_JSON')),
			(new ORM\Fields\IntegerField('LAST_ASSIGNMENT_VERSION'))
				->configureRequired()
				->configureDefaultValue(0)
			,
			(new ORM\Fields\DatetimeField('MANUALLY_SET_AT')),
			(new ORM\Fields\DatetimeField('UPDATED_AT'))
				->configureDefaultValue(static fn(): DateTime => new DateTime())
			,
		];
	}
}
