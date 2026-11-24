<?php

declare(strict_types=1);

namespace Bitrix\Pull\Service\PushSchedule;

use Bitrix\Main\Application;
use Bitrix\Main\DB\Connection;
use Bitrix\Main\DB\DuplicateEntryException;
use Bitrix\Main\DB\TransactionException;
use Bitrix\Main\Error;
use Bitrix\Main\ORM\Fields\BooleanField;
use Bitrix\Main\Result;
use Bitrix\Main\Type\DateTime;
use Bitrix\Pull\Model\PushScheduleExceptionTable;
use Bitrix\Pull\Model\PushSchedulePolicyTable;
use Bitrix\Pull\Model\PushScheduleRuleTable;

final class PolicyRepository
{
	private const MAX_BATCH_USER_IDS = 300;

	public const ERROR_REVISION_CONFLICT = 'REVISION_CONFLICT';
	public const ERROR_INTERNAL = 'INTERNAL_ERROR';

	public function __construct(private readonly ?Connection $connection = null)
	{
	}

	public function get(int $userId): array
	{
		$state = $this->getState($userId);

		$rules = [];
		$ruleRows = PushScheduleRuleTable::query()
			->setSelect(['ID', 'ACTIVE', 'NAME', 'ICON', 'DAYS_MASK', 'START_MINUTE', 'END_MINUTE'])
			->where('USER_ID', $userId)
			->addOrder('ID')
			->exec()
		;
		while ($rule = $ruleRows->fetch())
		{
			$rules[] = [
				'id' => (int)$rule['ID'],
				'active' => $this->booleanFieldValue(
					PushScheduleRuleTable::class,
					'ACTIVE',
					$rule['ACTIVE'],
				),
				'name' => (string)$rule['NAME'],
				'icon' => (string)$rule['ICON'],
				'days' => $this->maskToDays((int)$rule['DAYS_MASK']),
				'startMinute' => (int)$rule['START_MINUTE'],
				'endMinute' => (int)$rule['END_MINUTE'],
			];
		}

		$exceptionSourceIds = [];
		$exceptionRows = PushScheduleExceptionTable::query()
			->setSelect(['SOURCE_ID'])
			->where('USER_ID', $userId)
			->addOrder('SOURCE_ID')
			->exec()
		;
		while ($exception = $exceptionRows->fetch())
		{
			$exceptionSourceIds[] = (string)$exception['SOURCE_ID'];
		}

		return [
			'revision' => $state['revision'],
			'automaticEnabled' => $state['automaticEnabled'],
			'rules' => $rules,
			'exceptionSourceIds' => $exceptionSourceIds,
		];
	}

	/** @return array{revision: int, automaticEnabled: bool} */
	public function getState(int $userId): array
	{
		$policy = PushSchedulePolicyTable::query()
			->setSelect(['AUTO_ENABLED', 'REVISION'])
			->where('USER_ID', $userId)
			->fetch()
		;

		return [
			'revision' => $policy ? (int)$policy['REVISION'] : 0,
			'automaticEnabled' => $policy
				? $this->booleanFieldValue(
					PushSchedulePolicyTable::class,
					'AUTO_ENABLED',
					$policy['AUTO_ENABLED'],
				)
				: false,
		];
	}

	/**
	 * Returns stored delivery policies for a bounded user batch.
	 *
	 * @param int[] $userIds
	 * @return array<int, array>
	 */
	public function getForUsers(array $userIds): array
	{
		$userIds = array_values(array_unique(array_filter(
			array_map('intval', $userIds),
			static fn (int $userId): bool => $userId > 0,
		)));
		if (count($userIds) > self::MAX_BATCH_USER_IDS)
		{
			throw new \InvalidArgumentException('Push schedule policy batch is limited to 300 users.');
		}

		$policies = [];
		foreach ($userIds as $userId)
		{
			$policies[$userId] = [
				'exists' => false,
				'automaticEnabled' => false,
				'rules' => [],
				'exceptionSourceIds' => [],
			];
		}
		if ($userIds === [])
		{
			return $policies;
		}

		$policyRows = PushSchedulePolicyTable::query()
			->setSelect(['USER_ID', 'AUTO_ENABLED'])
			->whereIn('USER_ID', $userIds)
			->exec()
		;
		while ($policy = $policyRows->fetch())
		{
			$userId = (int)$policy['USER_ID'];
			$policies[$userId]['exists'] = true;
			$policies[$userId]['automaticEnabled'] = $this->booleanFieldValue(
				PushSchedulePolicyTable::class,
				'AUTO_ENABLED',
				$policy['AUTO_ENABLED'],
			);
		}

		$ruleRows = PushScheduleRuleTable::query()
			->setSelect(['USER_ID', 'ACTIVE', 'DAYS_MASK', 'START_MINUTE', 'END_MINUTE'])
			->whereIn('USER_ID', $userIds)
			->addOrder('USER_ID')
			->addOrder('ID')
			->exec()
		;
		while ($rule = $ruleRows->fetch())
		{
			$userId = (int)$rule['USER_ID'];
			$policies[$userId]['rules'][] = [
				'active' => $this->booleanFieldValue(
					PushScheduleRuleTable::class,
					'ACTIVE',
					$rule['ACTIVE'],
				),
				'daysMask' => (int)$rule['DAYS_MASK'],
				'startMinute' => (int)$rule['START_MINUTE'],
				'endMinute' => (int)$rule['END_MINUTE'],
			];
		}

		$exceptionRows = PushScheduleExceptionTable::query()
			->setSelect(['USER_ID', 'SOURCE_ID'])
			->whereIn('USER_ID', $userIds)
			->addOrder('USER_ID')
			->addOrder('SOURCE_ID')
			->exec()
		;
		while ($exception = $exceptionRows->fetch())
		{
			$userId = (int)$exception['USER_ID'];
			$policies[$userId]['exceptionSourceIds'][] = (string)$exception['SOURCE_ID'];
		}

		return $policies;
	}

	public function save(int $userId, array $policy, ?\Closure $policyMapper = null): Result
	{
		$result = new Result();
		$connection = $this->connection ?? Application::getConnection();
		$transactionStarted = false;
		$connection->startTransaction();
		$transactionStarted = true;
		try
		{
			if (!$this->compareAndSwap($connection, $userId, $policy))
			{
				$this->rollbackLocalTransaction($connection, $transactionStarted);

				return $result->addError(new Error('Push schedule revision conflict.', self::ERROR_REVISION_CONFLICT));
			}

			$foreignRuleIndex = $this->findForeignRuleIndex($userId, $policy['rules']);
			if ($foreignRuleIndex !== null)
			{
				$this->rollbackLocalTransaction($connection, $transactionStarted);

				return $result->addError(new Error(
					'Rule does not belong to the current user.',
					PolicyValidator::ERROR_VALIDATION,
					['field' => 'rules.id', 'ruleIndex' => $foreignRuleIndex],
				));
			}

			$this->replaceRules($connection, $userId, $policy['rules']);
			$this->replaceExceptions($connection, $userId, $policy['exceptionSourceIds']);
			$storedPolicy = $this->get($userId);
			$result->setData([
				'policy' => $policyMapper === null ? $storedPolicy : $policyMapper($storedPolicy),
			]);
			$this->commitLocalTransaction($connection, $transactionStarted);
		}
		catch (\Throwable)
		{
			if ($transactionStarted)
			{
				try
				{
					$this->rollbackLocalTransaction($connection, $transactionStarted);
				}
				catch (\Throwable)
				{
				}
			}

			return $result->addError(new Error('Cannot save push schedule policy.', self::ERROR_INTERNAL));
		}

		return $result;
	}

	private function commitLocalTransaction(Connection $connection, bool &$transactionStarted): void
	{
		$transactionStarted = false;
		$connection->commitTransaction();
	}

	private function rollbackLocalTransaction(Connection $connection, bool &$transactionStarted): void
	{
		$transactionStarted = false;
		try
		{
			$connection->rollbackTransaction();
		}
		catch (TransactionException)
		{
			// MySQL reports a completed rollback to a savepoint as unsupported nesting.
		}
	}

	private function compareAndSwap(Connection $connection, int $userId, array $policy): bool
	{
		if ($policy['revision'] === 0)
		{
			try
			{
				$addResult = PushSchedulePolicyTable::add([
					'USER_ID' => $userId,
					'AUTO_ENABLED' => $policy['automaticEnabled'],
					'REVISION' => 1,
					'UPDATED_AT' => new DateTime(),
				]);
			}
			catch (DuplicateEntryException)
			{
				return false;
			}

			if (!$addResult->isSuccess())
			{
				throw new \RuntimeException('Cannot save push schedule policy.');
			}

			return true;
		}

		$helper = $connection->getSqlHelper();
		$automaticEnabled = $policy['automaticEnabled'] ? 'Y' : 'N';
		$updatedAt = $helper->convertToDbDateTime(new DateTime());
		$connection->queryExecute(
			'UPDATE b_pull_push_schedule '
			. "SET AUTO_ENABLED = '{$automaticEnabled}', REVISION = REVISION + 1, UPDATED_AT = {$updatedAt} "
			. "WHERE USER_ID = {$userId} AND REVISION = {$policy['revision']}",
		);

		return $connection->getAffectedRowsCount() === 1;
	}

	private function replaceRules(Connection $connection, int $userId, array $rules): void
	{
		$storedMetadata = $this->getStoredRuleMetadata($userId, $rules);
		$connection->queryExecute("DELETE FROM b_pull_push_schedule_rule WHERE USER_ID = {$userId}");
		$rows = [];
		foreach ($rules as $rule)
		{
			$ruleId = $rule['id'];
			$existingMetadata = $ruleId === null ? null : ($storedMetadata[$ruleId] ?? null);
			$fields = [
				'USER_ID' => $userId,
				'ACTIVE' => $rule['active'],
				'NAME' => $rule['name'] ?? $existingMetadata['name'] ?? PolicyValidator::LEGACY_RULE_NAME_SENTINEL,
				'ICON' => $rule['icon'] ?? $existingMetadata['icon'] ?? PolicyValidator::DEFAULT_RULE_ICON,
				'DAYS_MASK' => $this->daysToMask($rule['days']),
				'START_MINUTE' => $rule['startMinute'],
				'END_MINUTE' => $rule['endMinute'],
			];
			if ($rule['id'] !== null)
			{
				$fields['ID'] = $rule['id'];
			}
			$rows[] = $fields;
		}
		if ($rows === [])
		{
			return;
		}

		// The rule entity has no persistence callbacks; events would split an autoincrement batch into single inserts.
		$addResult = PushScheduleRuleTable::addMulti($rows, true);
		if (!$addResult->isSuccess())
		{
			throw new \RuntimeException('Cannot save push schedule rules.');
		}
	}

	private function getStoredRuleMetadata(int $userId, array $rules): array
	{
		$ruleIds = array_values(array_filter(array_column($rules, 'id')));
		if ($ruleIds === [])
		{
			return [];
		}

		$metadata = [];
		$rows = PushScheduleRuleTable::query()
			->setSelect(['ID', 'NAME', 'ICON'])
			->where('USER_ID', $userId)
			->whereIn('ID', $ruleIds)
			->exec()
		;
		while ($row = $rows->fetch())
		{
			$metadata[(int)$row['ID']] = [
				'name' => (string)$row['NAME'],
				'icon' => (string)$row['ICON'],
			];
		}

		return $metadata;
	}

	private function replaceExceptions(Connection $connection, int $userId, array $exceptionSourceIds): void
	{
		$connection->queryExecute("DELETE FROM b_pull_push_schedule_exception WHERE USER_ID = {$userId}");
		if ($exceptionSourceIds === [])
		{
			return;
		}

		$rows = array_map(
			static fn (string $sourceId): array => [
				'USER_ID' => $userId,
				'SOURCE_ID' => $sourceId,
			],
			$exceptionSourceIds,
		);
		$addResult = PushScheduleExceptionTable::addMulti($rows);
		if (!$addResult->isSuccess())
		{
			throw new \RuntimeException('Cannot save push schedule exceptions.');
		}
	}

	private function findForeignRuleIndex(int $userId, array $rules): ?int
	{
		$ruleIds = array_values(array_filter(array_column($rules, 'id')));
		if ($ruleIds === [])
		{
			return null;
		}

		$ownedIds = array_column(PushScheduleRuleTable::query()
			->setSelect(['ID'])
			->where('USER_ID', $userId)
			->whereIn('ID', $ruleIds)
			->exec()
			->fetchAll()
		, 'ID');
		$ownedIds = array_fill_keys(array_map('intval', $ownedIds), true);

		foreach ($rules as $ruleIndex => $rule)
		{
			if ($rule['id'] !== null && !isset($ownedIds[$rule['id']]))
			{
				return (int)$ruleIndex;
			}
		}

		return null;
	}

	private function daysToMask(array $days): int
	{
		$mask = 0;
		foreach ($days as $day)
		{
			$mask |= 1 << ($day - 1);
		}

		return $mask;
	}

	private function maskToDays(int $mask): array
	{
		$days = [];
		for ($day = 1; $day <= 7; $day++)
		{
			if (($mask & (1 << ($day - 1))) !== 0)
			{
				$days[] = $day;
			}
		}

		return $days;
	}

	private function booleanFieldValue(string $tableClass, string $fieldName, mixed $value): bool
	{
		static $fields = [];

		$key = $tableClass . ':' . $fieldName;
		if (!isset($fields[$key]))
		{
			$field = $tableClass::getEntity()->getField($fieldName);
			if (!$field instanceof BooleanField)
			{
				throw new \LogicException("Field {$key} must be boolean.");
			}

			$fields[$key] = $field;
		}

		return $fields[$key]->booleanizeValue($value);
	}
}
