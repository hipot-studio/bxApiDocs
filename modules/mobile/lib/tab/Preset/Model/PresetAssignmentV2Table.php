<?php

namespace Bitrix\Mobile\Tab\Preset\Model;

use Bitrix\Main\ORM;
use Bitrix\Main\Type\DateTime;
use Bitrix\Mobile\Tab\Preset\Enum\AssignmentTargetType;
use Bitrix\Mobile\Tab\Preset\Enum\PresetSource;

class PresetAssignmentV2Table extends ORM\Data\DataManager
{
	public static function getTableName(): string
	{
		return 'b_mobile_tab_preset_assignment_v2';
	}

	public static function getMap(): array
	{
		return [
			(new ORM\Fields\IntegerField('ID'))
				->configurePrimary()
				->configureAutocomplete()
			,
			(new ORM\Fields\IntegerField('ASSIGNER_ID'))
				->configureRequired()
			,
			(new ORM\Fields\EnumField('PRESET_SOURCE'))
				->configureRequired()
				->configureValues(PresetSource::values())
			,
			(new ORM\Fields\StringField('PRESET_KEY'))
				->configureRequired()
				->configureSize(64)
			,
			(new ORM\Fields\TextField('PRESET_CONFIG_JSON')),
			(new ORM\Fields\EnumField('TARGET_TYPE'))
				->configureRequired()
				->configureValues(AssignmentTargetType::values())
			,
			(new ORM\Fields\IntegerField('TARGET_ID'))
				->configureRequired()
			,
			(new ORM\Fields\IntegerField('VERSION'))
				->configureRequired()
			,
			(new ORM\Fields\BooleanField('IS_ACTIVE'))
				->configureStorageValues('N', 'Y')
				->configureDefaultValue('Y')
			,
			(new ORM\Fields\DatetimeField('CREATED_AT'))
				->configureDefaultValue(static fn(): DateTime => new DateTime())
			,
			(new ORM\Fields\DatetimeField('UPDATED_AT'))
				->configureDefaultValue(static fn(): DateTime => new DateTime())
			,
		];
	}
}
