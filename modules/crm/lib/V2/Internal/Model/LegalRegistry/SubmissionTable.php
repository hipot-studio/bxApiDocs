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

final class SubmissionTable extends DataManager
{
	private const MAX_SNAPSHOT_BYTES = 1048576;

	public static function getTableName(): string
	{
		return 'b_crm_legal_submission';
	}

	public static function getMap(): array
	{
		return [
			(new Fields\IntegerField('ID'))->configurePrimary()->configureAutocomplete(),
			(new Fields\IntegerField('SOURCE_ID'))->configureRequired(),
			(new Fields\StringField('SUBMISSION_KEY'))
				->configureRequired()
				->configureBinary()
				->addValidator(static fn(mixed $value): bool|string => is_string($value) && strlen($value) >= 1 && strlen($value) <= 255
						? true
						: 'Invalid identity key size.',
				),
			(new Fields\StringField('PAYLOAD_HASH'))->configureRequired()->configureFormat('/^[a-f0-9]{64}$/D'),
			self::snapshot('MANIFEST'),
			self::utcDate('SERVER_RECEIVED_AT', true),
			(new Fields\EnumField('STATE'))->configureRequired()->configureValues(['pending', 'committed', 'failed']),
			(new Fields\StringField('ERROR_CODE'))
				->configureNullable()
				->addValidator(new Fields\Validators\LengthValidator(null, 64)),
			(new Fields\IntegerField('RESULT_ID'))->configureNullable(),
			(new Fields\IntegerField('ACTIVITY_ID'))->configureNullable(),
			self::snapshot('RESOLUTION_SNAPSHOT', true),
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
		// Event suppression in updateMulti must not bypass committed evidence protection.
		$error = self::immutableError($primary, $data);
		if ($error !== null)
		{
			$result->addError($error);
		}
	}

	private static function immutableError(mixed $primary, array $data): ?EntityError
	{
		if (empty($primary) || $data === [])
		{
			return null;
		}
		self::normalizePrimary($primary);
		$row = self::currentRow(self::class, (int)$primary['ID']);
		if ($row && $row['STATE'] === 'committed')
		{
			return new EntityError('Committed submission is immutable.', 'INVALID_STATE');
		}

		return null;
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
				if (!is_string($value) || strlen($value) > self::MAX_SNAPSHOT_BYTES)
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
