<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Integration\AI\TaskCreation\Duplicate;

use Bitrix\Im\Model\AiTaskQueueTable;
use Bitrix\Im\V2\Integration\AI\TaskCreation\AutoTaskType;
use Bitrix\Main\Application;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Main\Security\Random;
use Bitrix\Main\Type\DateTime;
use Bitrix\Main\Web\Json;

/**
 * State of the tasks held back until the duplicate verdict arrives and, when it says
 * "duplicate", until the initiator presses "create anyway".
 *
 * One row per anchor message in b_im_ai_task_queue under its own ENTITY_TYPE, so the live
 * operation barriers of the AI task flow never see it. A row belongs to one extraction run - the
 * same initiator and the same material the run started from, because the anchor alone does not
 * identify it: in the multiselect flow the anchor is the newest message of the selection, which
 * two overlapping selections share. A run takes new tasks only while it is still collecting, and
 * every task key carries the id of that run, so a late verdict of a finished run cannot land in
 * the next one. Every entry point that acts on the batch as a whole names the initiator and gets
 * nothing for a record of another one - the isolation is a property of the storage, not of the
 * discipline of its callers.
 *
 * Every change is conditional - params are replaced only while the row still holds what was read,
 * and creation paths claim the batch before doing external work. A task is removed before creation
 * so an ambiguous or failed result cannot create it twice.
 */
class PendingStorage
{
	/**
	 * Collection normally finishes in the same request. An abandoned run must not accept tasks
	 * from a later extraction of the same message.
	 */
	private const COLLECTING_TTL_SECONDS = 900;

	public const VERDICT_NEW = 'new';
	public const VERDICT_DUPLICATE = 'duplicate';

	/** Callback and button may update the same row at once. */
	private const WRITE_ATTEMPTS = 5;

	private const OPERATION_ID_LENGTH = 8;

	/** PARAMS is a MySQL TEXT column. */
	private const MAX_PARAMS_BYTES = 65535;

	/**
	 * Adds one extracted task to the batch of the anchor message, creating the batch if needed.
	 *
	 * @return string|null key of the added task, null when it could not be stored: the caller then
	 *         creates the task right away instead of waiting for a verdict nothing can deliver
	 */
	public function appendTask(int $anchorMessageId, array $context, array $taskEntry): ?string
	{
		$initiatorId = (int)($context['initiatorId'] ?? 0);
		if ($initiatorId <= 0)
		{
			return null;
		}

		return $this->modify(
			$anchorMessageId,
			function (?array $row) use ($context, $taskEntry): array {
				if ($row === null)
				{
					$params = $this->startBatch($context);
				}
				elseif ($this->isJoinable($row, $context))
				{
					$params = $row['params'];
				}
				else
				{
					return [null, null];
				}

				$index = (int)($params['nextTaskIndex'] ?? count($params['tasks'] ?? []));
				$key = ($params['operationId'] ?? '') . '-' . $index;

				$params['nextTaskIndex'] = $index + 1;
				$params['tasks'][$key] = $taskEntry;

				return [$params, $key];
			}
		);
	}

	public function removeTask(int $anchorMessageId, string $taskKey): bool
	{
		for ($attempt = 0; $attempt < self::WRITE_ATTEMPTS; $attempt++)
		{
			$row = $this->readRow($anchorMessageId);
			if ($row === null)
			{
				return true;
			}

			$params = $row['params'];
			if (!isset($params['tasks'][$taskKey]))
			{
				return true;
			}

			unset($params['tasks'][$taskKey]);

			if (empty($params['tasks']))
			{
				if ($this->deleteRow($row['id'], $row['raw']))
				{
					return true;
				}
			}
			elseif ($this->updateRow($row['id'], $row['raw'], $params))
			{
				return true;
			}
		}

		return false;
	}

	/**
	 * Closes the batch before its single verdict request is sent.
	 *
	 * @return array|null params of the closed batch, null when this initiator has nothing parked
	 *         for this message
	 */
	public function finalizeBatch(int $anchorMessageId, int $initiatorId, int $createdBeforeCheck = 0): ?array
	{
		return $this->modify($anchorMessageId, static function (?array $row) use ($initiatorId, $createdBeforeCheck): array {
			if ($row === null || (int)($row['params']['initiatorId'] ?? 0) !== $initiatorId)
			{
				return [null, null];
			}

			$params = $row['params'];
			if (($params['collecting'] ?? false) !== true)
			{
				return [null, null];
			}

			$params['collecting'] = false;
			$params['createdBeforeCheck'] = max(0, $createdBeforeCheck);

			return [$params, $params];
		});
	}

	/**
	 * Stores all verdicts and atomically claims the batch for resolution. A repeated callback or
	 * a callback from an earlier extraction run changes nothing.
	 *
	 * @param array<string, array{verdict: string, matchedTaskId: int, reason: string}> $verdicts
	 */
	public function applyVerdicts(
		int $anchorMessageId,
		string $operationId,
		int $initiatorId,
		array $verdicts,
	): ?array
	{
		return $this->modify($anchorMessageId, function (?array $row) use ($operationId, $initiatorId, $verdicts): array {
			if (
				$row === null
				|| ($row['params']['operationId'] ?? '') !== $operationId
				|| (int)($row['params']['initiatorId'] ?? 0) !== $initiatorId
				|| ($row['params']['collecting'] ?? true) === true
				|| (int)($row['params']['decisionMessageId'] ?? 0) > 0
				|| (int)($row['params']['resolvedAt'] ?? 0) > 0
			)
			{
				return [null, null];
			}

			$params = $row['params'];
			if (empty($params['tasks']))
			{
				return [null, null];
			}

			foreach ($params['tasks'] as $taskKey => $task)
			{
				$params['tasks'][$taskKey] = array_merge(
					$task,
					$verdicts[$taskKey] ?? [
						'verdict' => self::VERDICT_NEW,
						'matchedTaskId' => 0,
						'reason' => '',
					],
				);
			}

			$params['resolvedAt'] = time();

			return [$params, $params];
		});
	}

	/**
	 * Keeps duplicate tasks together with the message that carries the button.
	 *
	 * @param int $createdBeforeDecision tasks already created in this batch before the user decides
	 *
	 * @return bool false when the record is already gone - the batch was released by someone else
	 */
	public function keepForDecision(
		int $anchorMessageId,
		int $decisionMessageId,
		int $createdBeforeDecision = 0,
	): bool
	{
		$row = $this->readRow($anchorMessageId);
		if (
			$row === null
			|| empty($row['params']['tasks'])
			|| (int)($row['params']['resolvedAt'] ?? 0) <= 0
		)
		{
			return false;
		}

		$params = $row['params'];

		$params['collecting'] = false;
		$params['resolvedAt'] = 0;
		$params['decisionMessageId'] = $decisionMessageId;
		$params['createdBeforeDecision'] = max(0, $createdBeforeDecision);

		return $this->updateRow($row['id'], $row['raw'], $params);
	}

	/**
	 * Claims the pending batch for creation. Only the initiator can claim it and only for the
	 * message that carries the button.
	 */
	public function capture(int $anchorMessageId, int $userId, int $decisionMessageId): ?array
	{
		return $this->modify(
			$anchorMessageId,
			function (?array $row) use ($userId, $decisionMessageId): array {
				if ($row === null)
				{
					return [null, null];
				}

				$params = $row['params'];
				if (
					(int)($params['initiatorId'] ?? 0) !== $userId
					|| (int)($params['decisionMessageId'] ?? 0) !== $decisionMessageId
					|| (int)($params['resolvedAt'] ?? 0) > 0
				)
				{
					return [null, null];
				}

				$captured = $params;
				$params['resolvedAt'] = time();
				$params['decisionMessageId'] = 0;

				return [$params, $captured];
			},
		);
	}

	/**
	 * Claims a batch once when the verdict request could not be started.
	 */
	public function take(int $anchorMessageId): ?array
	{
		return $this->modify($anchorMessageId, static function (?array $row): array {
			if ($row === null)
			{
				return [null, null];
			}

			$params = $row['params'];
			if (
				(int)($params['decisionMessageId'] ?? 0) > 0
				|| (int)($params['resolvedAt'] ?? 0) > 0
			)
			{
				return [null, null];
			}

			$params['collecting'] = false;
			$params['resolvedAt'] = time();

			return [$params, $params];
		});
	}

	public function read(int $anchorMessageId): ?array
	{
		return $this->readRow($anchorMessageId)['params'] ?? null;
	}

	public function drop(int $anchorMessageId): void
	{
		AiTaskQueueTable::deleteBatch([
			'=ENTITY_TYPE' => AutoTaskType::PendingDuplicate->value,
			'=ENTITY_ID' => $this->buildEntityId($anchorMessageId),
		]);
	}

	/**
	 * A batch takes new tasks only from the run that started it: joining a closed batch, a batch of
	 * another initiator or one that already waits for an answer would create those tasks later in a
	 * foreign context and mix the verdicts and the buttons of two different runs.
	 */
	private function isJoinable(array $row, array $context): bool
	{
		$params = $row['params'];

		return (int)($params['initiatorId'] ?? 0) === (int)($context['initiatorId'] ?? 0)
			&& ($params['runKey'] ?? '') === $this->buildRunKey($context)
			&& ($params['collecting'] ?? false) === true
			&& (int)($params['decisionMessageId'] ?? 0) === 0
			&& (int)($params['resolvedAt'] ?? 0) === 0
			&& $this->getAge($row['dateCreate']) <= self::COLLECTING_TTL_SECONDS
		;
	}

	/**
	 * What tells two extraction runs apart. The anchor cannot: in the multiselect flow it is the
	 * newest message of the selection, so two overlapping selections of the same user share it.
	 * The material the run started from - its text and its selection - is what actually differs,
	 * and tasks of the other run must not inherit it from this record.
	 */
	private function buildRunKey(array $context): string
	{
		return sha1(Json::encode($context));
	}

	private function startBatch(array $context): array
	{
		$params = $context;

		$params['operationId'] = Random::getString(self::OPERATION_ID_LENGTH);
		$params['runKey'] = $this->buildRunKey($context);
		$params['collecting'] = true;
		$params['decisionMessageId'] = 0;
		$params['resolvedAt'] = 0;
		$params['nextTaskIndex'] = 0;
		$params['tasks'] = [];

		return $params;
	}

	/**
	 * Read-modify-write of one record. The modifier returns the new params (or null to write
	 * nothing) together with the value for the caller; the write lands only while the row still
	 * holds what the modifier saw, and a lost race is retried on freshly read params.
	 *
	 * @param callable(?array): array{0: ?array, 1: mixed} $modifier
	 */
	private function modify(int $anchorMessageId, callable $modifier): mixed
	{
		for ($attempt = 0; $attempt < self::WRITE_ATTEMPTS; $attempt++)
		{
			$row = $this->readRow($anchorMessageId);
			[$params, $result] = $modifier($row);

			if ($params === null || $this->store($anchorMessageId, $row, $params))
			{
				return $result;
			}
		}

		return null;
	}

	private function store(int $anchorMessageId, ?array $row, array $params): bool
	{
		if ($row !== null)
		{
			return $this->updateRow($row['id'], $row['raw'], $params);
		}

		$rawParams = Json::encode($params);
		if (strlen($rawParams) > self::MAX_PARAMS_BYTES)
		{
			return false;
		}

		AiTaskQueueTable::multiplyInsertWithoutDuplicate(
			[[
				'ENTITY_TYPE' => AutoTaskType::PendingDuplicate->value,
				'ENTITY_ID' => $this->buildEntityId($anchorMessageId),
				'PARAMS' => $rawParams,
				'DATE_CREATE' => new DateTime(),
			]],
			['DEADLOCK_SAFE' => true, 'UNIQUE_FIELDS' => ['ENTITY_TYPE', 'ENTITY_ID']]
		);

		// the unique index turns a lost insert race into a no-op, so the batch counts as ours
		// only if the record really carries this run
		$stored = $this->readRow($anchorMessageId);

		return $stored !== null && ($stored['params']['operationId'] ?? '') === $params['operationId'];
	}

	/**
	 * Conditional update: params are replaced only while the row still holds the value they were
	 * built from, so two callbacks racing on the same batch cannot overwrite each other.
	 */
	private function updateRow(int $rowId, string $expectedRaw, array $params): bool
	{
		$rawParams = Json::encode($params);
		if (strlen($rawParams) > self::MAX_PARAMS_BYTES)
		{
			return false;
		}

		if ($rawParams === $expectedRaw)
		{
			return true;
		}

		$fields = ['PARAMS' => $rawParams];
		$connection = Application::getConnection();
		$tableName = AiTaskQueueTable::getTableName();
		[$updateSql] = $connection->getSqlHelper()->prepareUpdate($tableName, $fields);
		$whereSql = Query::buildFilterSql(
			AiTaskQueueTable::getEntity(),
			['=ID' => $rowId, '=PARAMS' => $expectedRaw]
		);

		$connection->queryExecute("UPDATE {$tableName} SET {$updateSql} WHERE {$whereSql}");

		return $connection->getAffectedRowsCount() === 1;
	}

	/**
	 * Conditional delete: whoever gets the single affected row owns the batch, so a double click
	 * cannot create the same tasks twice.
	 */
	private function deleteRow(int $rowId, string $expectedRaw): bool
	{
		AiTaskQueueTable::deleteBatch(['=ID' => $rowId, '=PARAMS' => $expectedRaw]);

		return Application::getConnection()->getAffectedRowsCount() === 1;
	}

	private function getAge(?DateTime $dateCreate): int
	{
		// no creation date means the record is unreadable: treat it as overdue rather than immortal
		return $dateCreate === null ? PHP_INT_MAX : (time() - $dateCreate->getTimestamp());
	}

	private function buildEntityId(int $anchorMessageId): string
	{
		return AutoTaskType::PendingDuplicate->buildEntityId([$anchorMessageId]);
	}

	/**
	 * Params that cannot be read carry no batch.
	 */
	private function decodeParams(?string $rawParams): ?array
	{
		if ((string)$rawParams === '')
		{
			return null;
		}

		try
		{
			$params = Json::decode($rawParams);
		}
		catch (ArgumentException)
		{
			return null;
		}

		if (!is_array($params) || !is_string($params['operationId'] ?? null) || $params['operationId'] === '')
		{
			return null;
		}

		return $params;
	}

	/**
	 * @return array{id: int, raw: string, params: array, dateCreate: ?DateTime}|null
	 */
	private function readRow(int $anchorMessageId): ?array
	{
		$row = AiTaskQueueTable::query()
			->setSelect(['ID', 'PARAMS', 'DATE_CREATE'])
			->where('ENTITY_TYPE', AutoTaskType::PendingDuplicate->value)
			->where('ENTITY_ID', $this->buildEntityId($anchorMessageId))
			->fetch()
		;

		if ($row === false)
		{
			return null;
		}

		$rowId = (int)$row['ID'];
		$rawParams = (string)($row['PARAMS'] ?? '');
		$params = $this->decodeParams($rawParams);
		if ($params === null)
		{
			$this->deleteRow($rowId, $rawParams);

			return null;
		}

		return [
			'id' => $rowId,
			'raw' => $rawParams,
			'params' => $params,
			'dateCreate' => $row['DATE_CREATE'] instanceof DateTime ? $row['DATE_CREATE'] : null,
		];
	}
}
