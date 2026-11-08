<?php

namespace Bitrix\Mobile\Tab\Preset\Model;

use Bitrix\Main\ORM;
use Bitrix\Main\Type\DateTime;

class ManagedPresetTable extends ORM\Data\DataManager
{
	public static function getTableName(): string
	{
		return 'b_mobile_tab_preset';
	}

	public static function getMap(): array
	{
		return [
			(new ORM\Fields\IntegerField('ID'))
				->configurePrimary()
				->configureAutocomplete()
			,
			(new ORM\Fields\IntegerField('OWNER_ID'))
				->configureRequired()
			,
			(new ORM\Fields\StringField('TITLE'))
				->configureRequired()
				->configureSize(255)
			,
			(new ORM\Fields\TextField('CONFIG_JSON'))
				->configureRequired()
			,
			(new ORM\Fields\BooleanField('IS_DELETED'))
				->configureStorageValues('N', 'Y')
				->configureDefaultValue('N')
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
