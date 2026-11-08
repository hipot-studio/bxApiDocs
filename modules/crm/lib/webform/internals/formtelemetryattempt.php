<?php

declare(strict_types=1);

namespace Bitrix\Crm\WebForm\Internals;

use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Fields\BooleanField;
use Bitrix\Main\ORM\Fields\DatetimeField;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\ORM\Fields\StringField;

final class FormTelemetryAttemptTable extends DataManager
{
	public static function getTableName(): string
	{
		return 'b_crm_webform_telemetry_attempt';
	}

	public static function getMap(): array
	{
		return [
			(new StringField('ATTEMPT_ID'))
				->configurePrimary()
				->configureRequired()
				->configureSize(36)
			,
			(new IntegerField('FORM_ID'))
				->configureRequired()
				->addFetchDataModifier(static fn(mixed $value): int => (int)$value)
			,
			(new DatetimeField('DATE_START'))
				->configureNullable()
			,
			(new DatetimeField('DATE_EXPIRE'))
				->configureRequired()
			,
			(new StringField('SOURCE'))
				->configureRequired()
				->configureSize(20)
			,
			(new StringField('DEVICE'))
				->configureRequired()
				->configureSize(20)
			,
			(new BooleanField('VALIDATION_ERROR'))
				->configureRequired()
				->configureDefaultValue(false)
				->addFetchDataModifier(static fn(mixed $value): bool => (bool)$value)
			,
			(new BooleanField('OTHER_ERROR'))
				->configureRequired()
				->configureDefaultValue(false)
				->addFetchDataModifier(static fn(mixed $value): bool => (bool)$value)
			,
			(new BooleanField('COMPLETED'))
				->configureRequired()
				->configureDefaultValue(false)
				->addFetchDataModifier(static fn(mixed $value): bool => (bool)$value)
			,
		];
	}

	public static function isCacheable(): bool
	{
		return false;
	}
}
