<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Model\LegalRegistry;

use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Fields;

use Bitrix\Main\DB\SqlExpression;
use Bitrix\Main\Type\DateTime;

final class UsageTable extends DataManager
{
	public static function getTableName(): string
	{
		return 'b_crm_legal_usage';
	}

	public static function getMap(): array
	{
		return [
			(new Fields\IntegerField('ID'))->configurePrimary()->configureAutocomplete(),
			(new Fields\IntegerField('SOURCE_ID'))->configureRequired(),
			(new Fields\IntegerField('DOCUMENT_ID'))->configureRequired(),
			(new Fields\IntegerField('VERSION_ID'))->configureRequired(),
			(new Fields\IntegerField('REVISION'))->configureRequired(),
			(new Fields\BooleanField('CHECKED'))->configureRequired()->configureValues('N', 'Y'),
			(new Fields\BooleanField('REQUIRED'))->configureRequired()->configureValues('N', 'Y'),
			self::utcDate('DATE_START', true),
			self::utcDate('DATE_END'),
			(new Fields\StringField('FORM_NAME_SNAPSHOT'))
				->configureRequired()
				->addValidator(new Fields\Validators\LengthValidator(1, 255)),
		];
	}

	private static function utcDate(string $name, bool $required = false): Fields\DatetimeField
	{
		return (new Fields\DatetimeField($name))
			->configureNullable(!$required)
			->configureRequired($required)
			->configureUseTimezone(false)
			->addSaveDataModifier(static function (?DateTime $value): ?SqlExpression {
				return $value === null
					? null
					: new SqlExpression('?s', gmdate('Y-m-d H:i:s', $value->getTimestamp()));
			})
			->addFetchDataModifier(static function (?DateTime $value): ?DateTime {
				return $value === null
					? null
					: (new DateTime(
						$value->format('Y-m-d H:i:s'),
						'Y-m-d H:i:s',
						new \DateTimeZone('UTC'),
					))->disableUserTime();
			})
		;
	}
}
