<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Model\LegalRegistry;

use Bitrix\Main\DB\SqlExpression;
use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Data\DeleteResult;
use Bitrix\Main\ORM\Data\Result;
use Bitrix\Main\ORM\Data\UpdateResult;
use Bitrix\Main\ORM\EntityError;
use Bitrix\Main\ORM\Event;
use Bitrix\Main\ORM\EventResult;
use Bitrix\Main\ORM\Fields;
use Bitrix\Main\Type\DateTime;

final class FormSubmissionReceiptTable extends DataManager
{
	public static function getTableName(): string
	{
		return 'b_crm_webform_legal_receipt';
	}

	public static function getMap(): array
	{
		return [
			(new Fields\IntegerField('ID'))->configurePrimary()->configureAutocomplete(),
			(new Fields\IntegerField('SUBMISSION_ID'))->configureRequired()
				->addValidator(static fn(mixed $value): bool|string => is_int($value) && $value > 0
					? true : 'Invalid submission identity.'),
			(new Fields\StringField('REQUEST_HASH'))->configureRequired()->configureFormat('/^[a-f0-9]{64}$/D'),
			(new Fields\IntegerField('RESULT_ID'))->configureNullable()
				->addValidator(static fn(mixed $value): bool|string => $value === null || (is_int($value) && $value > 0)
					? true : 'Invalid CRM result identity.'),
			(new Fields\TextField('OUTCOME_SNAPSHOT'))->configureNullable()->configureLong()
				->addValidator(static function (mixed $value): bool|string {
					if ($value === null) { return true; }
					if (!is_string($value) || strlen($value) > 1048576) { return 'Invalid outcome snapshot.'; }
					json_decode($value, true);
					return json_last_error() === JSON_ERROR_NONE ? true : 'Invalid outcome snapshot.';
				}),
			(new Fields\EnumField('STATE'))->configureRequired()->configureValues(['pending', 'committed', 'failed']),
			(new Fields\IntegerField('ATTEMPT'))->configureRequired()->configureDefaultValue(0)
				->addValidator(static fn(mixed $value): bool|string => is_int($value) && $value >= 0
					? true : 'Invalid receipt attempt.'),
			self::utcDate('NEXT_RETRY_AT'),
			(new Fields\StringField('LAST_ERROR'))->configureNullable()
				->addValidator(new Fields\Validators\LengthValidator(null, 64)),
		];
	}

	public static function update($primary, array $data): UpdateResult
	{
		return self::guardedWrite([$primary], static fn() => parent::update($primary, $data), new UpdateResult());
	}

	public static function updateMulti($primaries, $data, $ignoreEvents = false): UpdateResult
	{
		return self::guardedWrite(
			$primaries,
			static fn() => parent::updateMulti($primaries, $data, $ignoreEvents),
			new UpdateResult(),
		);
	}

	public static function delete($primary): DeleteResult
	{
		return self::guardedWrite([$primary], static fn() => parent::delete($primary), new DeleteResult());
	}

	private static function guardedWrite(array $primaries, callable $write, Result $failure): Result
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
			if ($ids !== []) { self::currentRows(array_values($ids)); }
			$result = $write();
			if ($result->isSuccess()) { $connection->commitTransaction(); }
			else { self::rollbackWrite($connection); }
			$started = false;
			return $result;
		}
		catch (\Throwable)
		{
			if ($started)
			{
				try { self::rollbackWrite($connection); }
				catch (\Throwable) { /* Return only the storage error. */ }
			}
			$failure->addError(new EntityError('CRM receipt storage failed.', 'STORAGE_ERROR'));
			return $failure;
		}
		finally { $pool->useMasterOnly(false); }
	}

	private static function rollbackWrite(\Bitrix\Main\DB\Connection $connection): void
	{
		try { $connection->rollbackTransaction(); }
		catch (\Bitrix\Main\DB\TransactionException $exception)
		{
			if ($exception->getMessage() !== 'Nested rollbacks are unsupported.') { throw $exception; }
		}
	}

	private static function currentRows(array $ids): array
	{
		$query = new class(self::getEntity()) extends \Bitrix\Main\ORM\Query\Query {
			protected function query($sql)
			{
				$result = $this->entity->getConnection()->query($sql . ' FOR UPDATE');
				$result->setReplacedAliases($this->replaced_aliases);
				return $result;
			}
		};
		return $query->setSelect(['ID', 'STATE'])->setFilter(['@ID' => $ids])->setOrder(['ID' => 'ASC'])->fetchAll();
	}

	private static function utcDate(string $name): Fields\DatetimeField
	{
		return (new Fields\DatetimeField($name))
			->configureNullable(true)
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

	public static function onBeforeUpdate(Event $event): EventResult
	{
		$result = new EventResult();
		$error = self::immutableError($event->getParameter('id'), $event->getParameter('fields'));
		if ($error !== null) { $result->addError($error); }
		return $result;
	}

	public static function onBeforeDelete(Event $event): EventResult
	{
		$result = new EventResult();
		$error = self::immutableError($event->getParameter('id'), ['DELETE' => true]);
		if ($error !== null) { $result->addError($error); }
		return $result;
	}

	public static function checkFields(Result $result, mixed $primary, array $data): void
	{
		parent::checkFields($result, $primary, $data);
		$error = self::immutableError($primary, $data);
		if ($error !== null) { $result->addError($error); }
	}

	private static function immutableError(mixed $primary, array $data): ?EntityError
	{
		if (empty($primary) || $data === []) { return null; }
		self::normalizePrimary($primary);
		$row = self::currentRows([(int)$primary['ID']])[0] ?? false;
		return $row && $row['STATE'] === 'committed'
			? new EntityError('Committed CRM receipt is immutable.', 'INVALID_STATE') : null;
	}
}
