<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Model\LegalRegistry;

use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Fields;

use Bitrix\Main\DB\SqlExpression;
use Bitrix\Main\Type\DateTime;

final class DocumentTable extends DataManager
{
	public static function getTableName(): string
	{
		return 'b_crm_legal_document';
	}

	public static function getMap(): array
	{
		return [
			(new Fields\IntegerField('ID'))->configurePrimary()->configureAutocomplete(),
			(new Fields\StringField('NAME'))
				->configureRequired()
				->addValidator(new Fields\Validators\LengthValidator(1, 255)),
			(new Fields\StringField('ORIGIN_MODULE'))
				->configureNullable()
				->addValidator(new Fields\Validators\LengthValidator(null, 50)),
			(new Fields\IntegerField('ORIGIN_ID'))->configureNullable(),
			self::utcDate('CREATED_AT', true),
			(new Fields\IntegerField('CREATED_BY'))->configureRequired(),
			(new Fields\BooleanField('ARCHIVED'))
				->configureValues('N', 'Y')
				->configureDefaultValue('N')
				->configureRequired(),
			(new Fields\IntegerField('REVISION'))->configureRequired()->configureDefaultValue(1)
				->addValidator(static fn(mixed $value): bool|string => is_int($value) && $value > 0
					? true : 'Invalid document revision.'),
			(new Fields\TextField('METADATA'))->configureNullable()->configureLong()
				->addValidator(static function (mixed $value): bool|string {
					if ($value === null) { return true; }
					if (!is_string($value) || strlen($value) > 262144) { return 'Invalid metadata JSON.'; }
					json_decode($value, true);
					return json_last_error() === JSON_ERROR_NONE ? true : 'Invalid metadata JSON.';
				}),
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
