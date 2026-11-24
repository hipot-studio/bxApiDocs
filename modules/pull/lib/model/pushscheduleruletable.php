<?php

declare(strict_types=1);

namespace Bitrix\Pull\Model;

use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Fields\BooleanField;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\ORM\Fields\StringField;
use Bitrix\Main\ORM\Fields\Validators\LengthValidator;

final class PushScheduleRuleTable extends DataManager
{
	public static function getTableName(): string
	{
		return 'b_pull_push_schedule_rule';
	}

	public static function getMap(): array
	{
		$minuteValidator = static fn (int $value): true|string => $value >= 0 && $value <= 1439
			? true
			: 'Minute must be between 0 and 1439.';

		return [
			(new IntegerField('ID'))
				->configurePrimary()
				->configureAutocomplete()
			,
			(new IntegerField('USER_ID'))
				->configureRequired()
			,
			(new BooleanField('ACTIVE'))
				->configureRequired()
				->configureValues('N', 'Y')
				->configureDefaultValue(true)
			,
			(new StringField('NAME'))
				->configureDefaultValue('')
				->configureSize(100)
				->addValidator(new LengthValidator(0, 100))
			,
			(new StringField('ICON'))
				->configureRequired()
				->configureDefaultValue('other')
				->configureSize(16)
				->addValidator(static fn (string $value): true|string => in_array(
					$value,
					['vacation', 'sick', 'suitcase', 'home', 'other'],
					true,
				) ? true : 'Unknown push schedule rule icon.')
			,
			(new IntegerField('DAYS_MASK'))
				->configureRequired()
				->addValidator(static fn (int $value): true|string => $value >= 1 && $value <= 127
					? true
					: 'Days mask must be between 1 and 127.')
			,
			(new IntegerField('START_MINUTE'))
				->configureRequired()
				->addValidator($minuteValidator)
			,
			(new IntegerField('END_MINUTE'))
				->configureRequired()
				->addValidator($minuteValidator)
			,
		];
	}
}
