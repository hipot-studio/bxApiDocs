<?php

declare(strict_types=1);

namespace Bitrix\Crm\WebForm\Internals;

use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Fields\DateField;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\ORM\Fields\StringField;

final class FormTelemetryDailyTable extends DataManager
{
	public static function getTableName(): string
	{
		return 'b_crm_webform_telemetry_daily';
	}

	public static function getMap(): array
	{
		return [
			(new DateField('DATE_STAT'))
				->configurePrimary()
				->configureRequired()
			,
			(new IntegerField('FORM_ID'))
				->configurePrimary()
				->configureRequired()
				->addFetchDataModifier(static fn(mixed $value): int => (int)$value)
			,
			(new StringField('METRIC_CODE'))
				->configurePrimary()
				->configureRequired()
				->configureSize(50)
			,
			(new IntegerField('VALUE'))
				->configureRequired()
				->configureDefaultValue(0)
				->configureSize(8)
				->addFetchDataModifier(static fn(mixed $value): int => (int)$value)
			,
		];
	}

	public static function isCacheable(): bool
	{
		return false;
	}
}
