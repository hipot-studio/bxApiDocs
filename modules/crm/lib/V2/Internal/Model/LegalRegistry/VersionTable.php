<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Model\LegalRegistry;

use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Fields;

use Bitrix\Main\DB\SqlExpression;
use Bitrix\Main\ORM\Data\Result;
use Bitrix\Main\ORM\EntityError;
use Bitrix\Main\ORM\Event;
use Bitrix\Main\ORM\EventResult;
use Bitrix\Main\Type\DateTime;

final class VersionTable extends DataManager
{
	private const MAX_TEXT_BYTES = 262144;

	public static function getTableName(): string
	{
		return 'b_crm_legal_version';
	}

	public static function getMap(): array
	{
		return [
			(new Fields\IntegerField('ID'))->configurePrimary()->configureAutocomplete(),
			(new Fields\IntegerField('DOCUMENT_ID'))->configureRequired(),
			(new Fields\IntegerField('NUMBER'))->configureRequired(),
			(new Fields\IntegerField('REVISION'))->configureRequired(),
			(new Fields\EnumField('STATE'))->configureRequired()->configureValues(['draft', 'published', 'archived']),
			(new Fields\StringField('LANGUAGE_ID'))
				->configureRequired()
				->addValidator(new Fields\Validators\LengthValidator(1, 10)),
			self::text('TEMPLATE', false),
			self::text('LABEL', false),
			self::text('SUBSTITUTION_SCHEMA', true),
			(new Fields\StringField('DISPLAY_URL'))
				->configureNullable()
				->addValidator(new Fields\Validators\LengthValidator(null, 2048)),
			(new Fields\StringField('CONTENT_HASH'))->configureRequired()->configureFormat('/^[a-f0-9]{64}$/D'),
			self::utcDate('VALID_FROM'),
			self::utcDate('VALID_TO'),
			self::text('EXPIRY_RULE', true),
			self::utcDate('PUBLISHED_AT'),
			(new Fields\IntegerField('PUBLISHED_BY'))->configureNullable(),
		];
	}

	public static function update($primary, array $data): \Bitrix\Main\ORM\Data\UpdateResult
	{
		return self::guardedUpdate([$primary], static fn() => parent::update($primary, $data));
	}

	public static function updateMulti($primaries, $data, $ignoreEvents = false): \Bitrix\Main\ORM\Data\UpdateResult
	{
		return self::guardedUpdate($primaries, static fn() => parent::updateMulti($primaries, $data, $ignoreEvents));
	}

	private static function guardedUpdate(array $primaries, callable $write): \Bitrix\Main\ORM\Data\UpdateResult
	{
		$pool = \Bitrix\Main\Application::getInstance()->getConnectionPool();
		$pool->useMasterOnly(true);
		$connection = self::getEntity()->getConnection();
		$started = false;
		try
		{
			$ids = [];
			foreach ($primaries as $primary)
			{
				self::normalizePrimary($primary);
				self::validatePrimary($primary);
				$ids[(int)$primary['ID']] = (int)$primary['ID'];
			}
			ksort($ids, SORT_NUMERIC);
			$connection->startTransaction();
			$started = true;
			if ($ids !== []) { self::currentRows(self::class, ['@ID' => array_values($ids)]); }
			$result = $write();
			$started = false;
			if ($result->isSuccess()) { $connection->commitTransaction(); }
			else { self::rollbackUpdate(); }

			return $result;
		}
		catch (\Throwable)
		{
			if ($started)
			{
				try { self::rollbackUpdate(); }
				catch (\Throwable) { /* Return only the safe storage error. */ }
			}
			$result = new \Bitrix\Main\ORM\Data\UpdateResult();
			$result->addError(new EntityError('Registry storage failed.', 'STORAGE_ERROR'));

			return $result;
		}
		finally
		{
			$pool->useMasterOnly(false);
		}
	}

	private static function rollbackUpdate(): void
	{
		try { self::getEntity()->getConnection()->rollbackTransaction(); }
		catch (\Bitrix\Main\DB\TransactionException $exception)
		{
			// MySQL has already rolled back the savepoint before this native exception.
			if ($exception->getMessage() !== 'Nested rollbacks are unsupported.') { throw $exception; }
		}
	}

	public static function onBeforeUpdate(Event $event): EventResult
	{
		$result = new EventResult();
		$error = self::immutableError($event->getParameter('id'), $event->getParameter('fields'));
		if ($error !== null)
		{
			$result->addError($error);
		}

		return $result;
	}

	public static function checkFields(Result $result, mixed $primary, array $data): void
	{
		parent::checkFields($result, $primary, $data);
		// updateMulti(ignoreEvents: true) still calls checkFields for every primary before writing.
		$error = self::immutableError($primary, $data);
		if ($error !== null)
		{
			$result->addError($error);
		}
	}

	private static function immutableError(mixed $primary, array $data): ?EntityError
	{
		if (empty($primary))
		{
			return null;
		}
		self::normalizePrimary($primary);
		$row = self::currentRow(self::class, (int)$primary['ID']);
		if ($row && in_array($row['STATE'], ['published', 'archived'], true))
		{
			if ($data !== [] && ($data !== ['STATE' => 'archived']))
			{
				return new EntityError('Published version content is immutable.', 'VERSION_IMMUTABLE');
			}
		}

		return null;
	}

	private static function text(string $name, bool $json): Fields\TextField
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

		return $field->configureRequired()->configureLong()
			->addValidator(static function (mixed $value) use ($json): bool|string {
				if (!is_string($value) || strlen($value) > self::MAX_TEXT_BYTES || preg_match('//u', $value) !== 1)
				{
					return 'Invalid text snapshot size or encoding.';
				}
				if ($json)
				{
					json_decode($value, true);
					if (json_last_error() !== JSON_ERROR_NONE)
					{
						return 'Invalid JSON snapshot.';
					}
				}

				return true;
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

	/** Fetch current locked values through native ORM binary/date modifiers, never a snapshot reread. */
	private static function currentRows(string $model, array $filter): array
	{
		$query = new class($model::getEntity()) extends \Bitrix\Main\ORM\Query\Query {
			protected function query($sql)
			{
				// Native Query has no locking setter. Use its SQL branch, bypassing caches/NoSQL/slaves.
				$result = $this->entity->getConnection()->query($sql . ' FOR UPDATE');
				$result->setReplacedAliases($this->replaced_aliases);
				if ($this->isFetchModificationRequired())
				{
					$result->addFetchDataModifier([$this, 'fetchDataModificationCallback']);
				}

				return $result;
			}
		};

		return $query->setSelect(['*'])->setFilter($filter)->setOrder(['ID' => 'ASC'])->fetchAll();
	}

	private static function currentRow(string $model, int $id): array|false
	{
		if ($id <= 0 || $id > 2147483647) { return false; }

		return self::currentRows($model, ['=ID' => $id])[0] ?? false;
	}
}
