<?php

namespace Bitrix\Mobile\Tab\Preset\Model;

use Bitrix\Main\ORM;
use Bitrix\Main\Type\DateTime;
use Bitrix\Mobile\Tab\Preset\Enum\AssignmentTargetType;

class PresetAssignmentTable extends ORM\Data\DataManager
{
	public static function getTableName(): string
	{
		return 'b_mobile_tab_preset_assignment';
	}

	public static function getMap(): array
	{
		return [
			(new ORM\Fields\IntegerField('ID'))
				->configurePrimary()
				->configureAutocomplete()
			,
			(new ORM\Fields\IntegerField('PRESET_ID'))
				->configureRequired()
			,
			(new ORM\Fields\IntegerField('ASSIGNER_ID'))
				->configureRequired()
			,
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
