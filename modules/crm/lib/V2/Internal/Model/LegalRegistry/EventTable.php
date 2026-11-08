<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Model\LegalRegistry;

use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Fields;
use Bitrix\Main\DB\SqlExpression;
use Bitrix\Main\Type\DateTime;

final class EventTable extends DataManager
{
	public static function getTableName(): string
	{
		return 'b_crm_legal_event';
	}

	public static function getMap(): array
	{
		return [
			(new Fields\IntegerField('ID'))->configurePrimary()->configureAutocomplete(),
			(new Fields\IntegerField('SUBJECT_ID'))->configureNullable(),
			(new Fields\IntegerField('FACT_ID'))->configureNullable(),
			(new Fields\StringField('EVENT_KEY'))->configureRequired()->configureBinary()
				->addValidator(static fn(mixed $value): bool|string => is_string($value) && strlen($value) >= 1 && strlen($value) <= 255 ? true : 'Invalid identity key size.'),
			(new Fields\StringField('KIND'))->configureRequired()->addValidator(new Fields\Validators\LengthValidator(1, 32)),
			(new Fields\IntegerField('ACTOR_ID'))->configureRequired(),
			self::utcDate('EVENT_DATE', true),
			self::utcDate('SERVER_TIME', true),
			self::snapshot('PAYLOAD'),
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
