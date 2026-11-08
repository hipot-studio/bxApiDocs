<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Model\LegalRegistry;

use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Fields;
use Bitrix\Main\DB\SqlExpression;
use Bitrix\Main\Type\DateTime;

final class FactTable extends DataManager
{
	public static function getTableName(): string
	{
		return 'b_crm_legal_fact';
	}

	public static function getMap(): array
	{
		return [
			(new Fields\IntegerField('ID'))->configurePrimary()->configureAutocomplete(),
			(new Fields\IntegerField('SUBMISSION_ID'))->configureRequired(),
			(new Fields\IntegerField('DOCUMENT_ID'))->configureRequired(),
			(new Fields\IntegerField('VERSION_ID'))->configureRequired(),
			(new Fields\IntegerField('SUBJECT_ID'))->configureRequired(),
			self::snapshot('ORIGINAL_SUBJECT'),
			self::utcDate('ACCEPTED_AT', true),
			self::text('EFFECTIVE_TEXT'),
			self::text('EFFECTIVE_LABEL'),
			self::snapshot('SUBSTITUTIONS'),
			(new Fields\StringField('TEXT_HASH'))->configureRequired()->configureFormat('/^[a-f0-9]{64}$/D'),
			self::snapshot('RULE_SNAPSHOT'),
			(new Fields\StringField('TIMEZONE'))->configureRequired()->addValidator(new Fields\Validators\LengthValidator(1, 64)),
			self::utcDate('EXPIRES_AT'),
		];
	}

	public static function update($primary, array $data): \Bitrix\Main\ORM\Data\UpdateResult
	{
		$result = new \Bitrix\Main\ORM\Data\UpdateResult();
		$result->addError(new \Bitrix\Main\ORM\EntityError('Evidence is immutable.', 'INVALID_STATE'));

		return $result;
	}

	public static function updateMulti($primaries, $data, $ignoreEvents = false): \Bitrix\Main\ORM\Data\UpdateResult
	{
		return self::update($primaries, (array)$data);
	}

	public static function delete($primary): \Bitrix\Main\ORM\Data\DeleteResult
	{
		$result = new \Bitrix\Main\ORM\Data\DeleteResult();
		$result->addError(new \Bitrix\Main\ORM\EntityError('Evidence is immutable.', 'INVALID_STATE'));

		return $result;
	}

	private static function text(string $name): Fields\TextField
	{
		return (new class($name) extends Fields\TextField {
			public function cast(mixed $value): mixed
			{
				if (!is_string($value)) { throw new \InvalidArgumentException('Text snapshot must be a string.'); }

				return $value;
			}
		})->configureRequired()->configureLong()->addValidator(static fn(mixed $value): bool|string => is_string($value) && strlen($value) <= 262144 && preg_match('//u', $value) === 1 ? true : 'Invalid text snapshot.');
	}

	private static function snapshot(string $name, bool $nullable = false): Fields\TextField
	{
		$field = new class($name) extends Fields\TextField {
			public function cast(mixed $value): mixed
			{
				if (!is_string($value) && !($this->isNullable() && $value === null))
				{
					throw new \InvalidArgumentException('Text snapshot must be a string.');
				}

				return $value;
			}
		};

		return $field->configureRequired(!$nullable)->configureNullable($nullable)->configureLong()
			->addValidator(static function (mixed $value) use ($nullable): bool|string {
				if ($nullable && $value === null)
				{
					return true;
				}
				if (!is_string($value) || strlen($value) > 1048576)
				{
					return 'Invalid JSON snapshot size.';
				}
				json_decode($value, true);

				return json_last_error() === JSON_ERROR_NONE ? true : 'Invalid JSON snapshot.';
			})
		;
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
