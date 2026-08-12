<?php
declare(strict_types=1);

namespace Bitrix\Landing\Integration\AiAssistant\Service;

use Bitrix\Landing\Integration\AiAssistant\Model\AiSiteBindingTable;
use Bitrix\Main\Application;
use Bitrix\Main\DB\SqlQueryException;
use Bitrix\Main\Type\DateTime;

class AiSiteBindingService
{
	private const LOCK_TIMEOUT_SECONDS = 5;
	private const EVENT_LOG_AUDIT_TYPE_DUPLICATE_USER_SITE = 'LANDING_AI_SITE_BINDING_DUPLICATE_USER_SITE';

	private const SELECT_FIELDS = [
		'ID',
		'USER_ID',
		'SITE_ID',
		'GENERATION_ID',
		'DATE_CREATE',
		'DATE_MODIFY',
	];

	public function getOrCreateFreeDraft(int $userId): int
	{
		$userId = $this->requirePositiveId($userId, 'userId');

		return $this->withDbLock($this->getDraftLockName($userId), function () use ($userId): int
		{
			return $this->getOrCreateFreeDraftInternal($userId);
		});
	}

	public function reserveDraftForGeneration(int $bindingId, int $userId, int $generationId): bool
	{
		$bindingId = $this->normalizePositiveId($bindingId);
		$userId = $this->normalizePositiveId($userId);
		$generationId = $this->normalizePositiveId($generationId);
		if ($bindingId <= 0 || $userId <= 0 || $generationId <= 0)
		{
			return false;
		}

		return $this->executeConditionalUpdate(
			[
				'GENERATION_ID' => $generationId,
				'DATE_MODIFY' => new DateTime(),
			],
			[
				"ID = {$bindingId}",
				"USER_ID = {$userId}",
				'SITE_ID IS NULL',
				'GENERATION_ID IS NULL',
			],
		) === 1;
	}

	public function isFreeDraftForGeneration(int $bindingId, int $userId): bool
	{
		$row = $this->getByIdForUser($bindingId, $userId);

		return $row !== null
			&& $row['SITE_ID'] === null
			&& $row['GENERATION_ID'] === null
		;
	}

	public function completeGeneration(int $generationId, int $userId, int $siteId): bool
	{
		$generationId = $this->normalizePositiveId($generationId);
		$userId = $this->normalizePositiveId($userId);
		$siteId = $this->normalizePositiveId($siteId);
		if ($generationId <= 0 || $userId <= 0 || $siteId <= 0)
		{
			return false;
		}

		try
		{
			return $this->executeConditionalUpdate(
				[
					'SITE_ID' => $siteId,
					// A completed generation breaks the consecutive moderation-block streak.
					'MODERATION_BLOCK_COUNT' => 0,
					'DATE_MODIFY' => new DateTime(),
				],
				[
					"GENERATION_ID = {$generationId}",
					"USER_ID = {$userId}",
					'SITE_ID IS NULL',
				],
			) === 1;
		}
		catch (SqlQueryException $exception)
		{
			$existingBinding = $this->findByUserAndSite($userId, $siteId);
			if ($existingBinding !== null)
			{
				$this->logDuplicateUserSiteConflict($generationId, $userId, $siteId, $existingBinding, $exception);

				return true;
			}

			throw $exception;
		}
	}

	/**
	 * Consecutive moderation-block ceiling per draft (Q-2). Once reached, a new generation
	 * on the draft is refused with a soft "too many attempts" message until it resets.
	 */
	public const MODERATION_BLOCK_LIMIT = 3;

	/**
	 * Reaction to a moderation block on a draft: atomically frees the reservation (so the same
	 * chat can retry) and bumps the consecutive-block counter. Keyed on GENERATION_ID, so repeated
	 * status polls of the same failed generation cannot double-count (the second call matches 0 rows).
	 *
	 * Create-only: the edit flow does not reserve a binding, so there is nothing to release there.
	 */
	public function registerModerationBlock(int $generationId, int $userId): bool
	{
		$generationId = $this->normalizePositiveId($generationId);
		$userId = $this->normalizePositiveId($userId);
		if ($generationId <= 0 || $userId <= 0)
		{
			return false;
		}

		$connection = Application::getConnection();
		$sql = sprintf(
			'UPDATE %s SET GENERATION_ID = NULL, MODERATION_BLOCK_COUNT = MODERATION_BLOCK_COUNT + 1, DATE_MODIFY = %s'
			. ' WHERE GENERATION_ID = %d AND USER_ID = %d AND SITE_ID IS NULL',
			AiSiteBindingTable::getTableName(),
			$this->convertToSqlValue(new DateTime()),
			$generationId,
			$userId,
		);
		$connection->queryExecute($sql);

		return (int)$connection->getAffectedRowsCount() === 1;
	}

	public function getModerationBlockCount(int $bindingId, int $userId): int
	{
		$bindingId = $this->normalizePositiveId($bindingId);
		$userId = $this->normalizePositiveId($userId);
		if ($bindingId <= 0 || $userId <= 0)
		{
			return 0;
		}

		$row = AiSiteBindingTable::query()
			->setSelect(['MODERATION_BLOCK_COUNT'])
			->where('ID', '=', $bindingId)
			->where('USER_ID', '=', $userId)
			->setLimit(1)
			->fetch()
		;

		return is_array($row) ? max(0, (int)($row['MODERATION_BLOCK_COUNT'] ?? 0)) : 0;
	}

	public function isModerationBlockLimitReached(int $bindingId, int $userId): bool
	{
		return $this->getModerationBlockCount($bindingId, $userId) >= self::MODERATION_BLOCK_LIMIT;
	}

	public function getOrCreateForSite(int $userId, int $siteId): int
	{
		$userId = $this->requirePositiveId($userId, 'userId');
		$siteId = $this->requirePositiveId($siteId, 'siteId');

		return $this->withDbLock($this->getSiteLockName($userId, $siteId), function () use ($userId, $siteId): int
		{
			return $this->getOrCreateForSiteInternal($userId, $siteId);
		});
	}

	public function getByIdForUser(int $bindingId, int $userId): ?array
	{
		$bindingId = $this->normalizePositiveId($bindingId);
		$userId = $this->normalizePositiveId($userId);
		if ($bindingId <= 0 || $userId <= 0)
		{
			return null;
		}

		$row = AiSiteBindingTable::query()
			->setSelect(self::SELECT_FIELDS)
			->where('ID', '=', $bindingId)
			->where('USER_ID', '=', $userId)
			->setLimit(1)
			->fetch()
		;

		return $this->normalizeRow($row);
	}

	public function findByGenerationId(int $generationId): ?array
	{
		$generationId = $this->normalizePositiveId($generationId);
		if ($generationId <= 0)
		{
			return null;
		}

		$row = AiSiteBindingTable::query()
			->setSelect(self::SELECT_FIELDS)
			->where('GENERATION_ID', '=', $generationId)
			->setLimit(1)
			->fetch()
		;

		return $this->normalizeRow($row);
	}

	private function getOrCreateFreeDraftInternal(int $userId): int
	{
		$row = $this->findFreeDraft($userId);
		if ($row !== null)
		{
			return (int)$row['ID'];
		}

		return $this->add([
			'USER_ID' => $userId,
			'SITE_ID' => null,
			'GENERATION_ID' => null,
			'DATE_CREATE' => new DateTime(),
			'DATE_MODIFY' => new DateTime(),
		]);
	}

	private function getOrCreateForSiteInternal(int $userId, int $siteId): int
	{
		$row = $this->findByUserAndSite($userId, $siteId);
		if ($row)
		{
			return (int)$row['ID'];
		}

		return $this->add([
			'USER_ID' => $userId,
			'SITE_ID' => $siteId,
			'GENERATION_ID' => null,
			'DATE_CREATE' => new DateTime(),
			'DATE_MODIFY' => new DateTime(),
		]);
	}

	protected function findFreeDraft(int $userId): ?array
	{
		$row = AiSiteBindingTable::query()
			->setSelect(self::SELECT_FIELDS)
			->where('USER_ID', '=', $userId)
			->whereNull('SITE_ID')
			->whereNull('GENERATION_ID')
			->setOrder(['ID' => 'ASC'])
			->setLimit(1)
			->fetch()
		;

		return $this->normalizeRow($row);
	}

	protected function findByUserAndSite(int $userId, int $siteId): ?array
	{
		$row = AiSiteBindingTable::query()
			->setSelect(self::SELECT_FIELDS)
			->where('USER_ID', '=', $userId)
			->where('SITE_ID', '=', $siteId)
			->setOrder(['ID' => 'ASC'])
			->setLimit(1)
			->fetch()
		;

		return $this->normalizeRow($row);
	}

	private function logDuplicateUserSiteConflict(
		int $generationId,
		int $userId,
		int $siteId,
		array $existingBinding,
		SqlQueryException $exception,
	): void
	{
		if (!class_exists(\CEventLog::class))
		{
			return;
		}

		\CEventLog::Add([
			'SEVERITY' => 'WARNING',
			'AUDIT_TYPE_ID' => self::EVENT_LOG_AUDIT_TYPE_DUPLICATE_USER_SITE,
			'MODULE_ID' => 'landing',
			'ITEM_ID' => (string)$siteId,
			'DESCRIPTION' => implode(PHP_EOL, [
				'AI site binding already exists for USER_ID + SITE_ID.',
				"GENERATION_ID: {$generationId}",
				"USER_ID: {$userId}",
				"SITE_ID: {$siteId}",
				'EXISTING_BINDING_ID: ' . (int)($existingBinding['ID'] ?? 0),
				'EXISTING_BINDING_GENERATION_ID: ' . (int)($existingBinding['GENERATION_ID'] ?? 0),
				'DB_ERROR: ' . $exception->getMessage(),
			]),
		]);
	}

	protected function withDbLock(string $lockName, callable $callback): mixed
	{
		$connection = Application::getConnection();
		if (!$connection->lock($lockName, self::LOCK_TIMEOUT_SECONDS))
		{
			throw new \RuntimeException("Unable to acquire AI site binding lock: {$lockName}.");
		}

		try
		{
			return $callback();
		}
		finally
		{
			$connection->unlock($lockName);
		}
	}

	protected function executeConditionalUpdate(array $fields, array $where): int
	{
		$connection = Application::getConnection();
		$set = [];
		foreach ($fields as $fieldName => $value)
		{
			$set[] = "{$fieldName} = " . $this->convertToSqlValue($value);
		}

		$sql = sprintf(
			'UPDATE %s SET %s WHERE %s',
			AiSiteBindingTable::getTableName(),
			implode(', ', $set),
			implode(' AND ', $where),
		);
		$connection->queryExecute($sql);

		return (int)$connection->getAffectedRowsCount();
	}

	protected function add(array $fields): int
	{
		$result = AiSiteBindingTable::add($fields);
		if (!$result->isSuccess())
		{
			throw new \RuntimeException(implode('; ', $result->getErrorMessages()));
		}

		return (int)$result->getId();
	}

	private function convertToSqlValue(mixed $value): string
	{
		$sqlHelper = Application::getConnection()->getSqlHelper();
		if ($value instanceof DateTime)
		{
			return $sqlHelper->convertToDbDateTime($value);
		}

		if ($value === null)
		{
			return 'NULL';
		}

		return (string)(int)$value;
	}

	private function getDraftLockName(int $userId): string
	{
		return "landing_ai_site_draft_{$userId}";
	}

	private function getSiteLockName(int $userId, int $siteId): string
	{
		return "landing_ai_site_site_{$userId}_{$siteId}";
	}

	private function normalizeRow($row): ?array
	{
		if (!is_array($row))
		{
			return null;
		}

		return [
			'ID' => (int)($row['ID'] ?? 0),
			'USER_ID' => (int)($row['USER_ID'] ?? 0),
			'SITE_ID' => AiSiteBindingTable::normalizeNullableId($row['SITE_ID'] ?? null),
			'GENERATION_ID' => AiSiteBindingTable::normalizeNullableId($row['GENERATION_ID'] ?? null),
			'DATE_CREATE' => $row['DATE_CREATE'] ?? null,
			'DATE_MODIFY' => $row['DATE_MODIFY'] ?? null,
		];
	}

	private function requirePositiveId(int $value, string $name): int
	{
		$value = $this->normalizePositiveId($value);
		if ($value <= 0)
		{
			throw new \InvalidArgumentException("{$name} must be positive.");
		}

		return $value;
	}

	private function normalizePositiveId(int $value): int
	{
		return $value > 0 ? $value : 0;
	}
}
