<?php

declare(strict_types=1);

namespace Bitrix\Timeman\V2\Internal\Repository;

use Bitrix\Main\Application;
use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Bitrix\Main\Type\DateTime;
use Bitrix\Timeman\V2\Internal\Model\RecordIntentStateTable;

class RecordIntentStateRepository
{
	private const ERROR_PERIOD_MISMATCH_OR_LIMIT = 'period_mismatch_or_limit';

	/**
	 * Reads the single intent-state row for the user, or null when it does not exist yet.
	 */
	public function getByUserId(int $userId): ?array
	{
		$row = RecordIntentStateTable::query()
			->setSelect([
				'ID',
				'USER_ID',
				'FIRST_USE_AT',
				'PERIOD_KEY',
				'TARGET_START_AT',
				'PERIOD_END_AT',
				'SHOW_COUNT',
				'DISMISS_COUNT',
				'SUPPRESSED_UNTIL',
				'HAS_TARGET_ACTION',
				'LAST_REGISTERED_AT',
				'LAST_SHOWN_AT',
			])
			->where('USER_ID', $userId)
			->setLimit(1)
			->exec()
			->fetch();

		return is_array($row) ? $this->normalizeRow($row) : null;
	}

	/**
	 * Inserts the row stamping FIRST_USE_AT; on USER_ID conflict FIRST_USE_AT is preserved (no-op).
	 */
	public function initFirstUse(int $userId, int $nowUtc): Result
	{
		$result = new Result();

		$connection = Application::getConnection();
		$helper = $connection->getSqlHelper();

		$now = new DateTime();
		$insertData = [
			'USER_ID' => $userId,
			'FIRST_USE_AT' => $nowUtc,
			'CREATED_AT' => $now,
			'UPDATED_AT' => $now,
		];

		// On conflict by USER_ID keep the existing row untouched: only refresh UPDATED_AT,
		// never overwrite FIRST_USE_AT.
		$updateData = [
			'UPDATED_AT' => $now,
		];

		$merge = $helper->prepareMerge(
			RecordIntentStateTable::getTableName(),
			['USER_ID'],
			$insertData,
			$updateData,
		);

		if (($merge[0] ?? '') === '')
		{
			return $result->addError(new Error('Error constructing merge query'));
		}

		$connection->query($merge[0]);

		$state = $this->getByUserId($userId);
		if ($state !== null)
		{
			$result->setData($state);
		}

		return $result;
	}

	/**
	 * Re-initializes the row for a new period: sets period fields, resets counters, flags and
	 * suppression. FIRST_USE_AT is never reset.
	 */
	public function resetPeriod(
		int $userId,
		string $periodKey,
		int $targetStartAt,
		int $periodEndAt,
	): Result
	{
		$result = new Result();

		$connection = Application::getConnection();
		$helper = $connection->getSqlHelper();

		$now = new DateTime();
		$periodFields = [
			'PERIOD_KEY' => $periodKey,
			'TARGET_START_AT' => $targetStartAt,
			'PERIOD_END_AT' => $periodEndAt,
			'SHOW_COUNT' => 0,
			'DISMISS_COUNT' => 0,
			'HAS_TARGET_ACTION' => 'N',
			'SUPPRESSED_UNTIL' => 0,
			// New period — no shows yet, the retry interval starts from scratch.
			'LAST_SHOWN_AT' => 0,
			'UPDATED_AT' => $now,
		];

		// FIRST_USE_AT participates only on insert (defaults to the period start when the row is new),
		// it is intentionally absent from the update branch so an existing first-use stamp survives.
		$insertData = $periodFields + [
			'USER_ID' => $userId,
			'FIRST_USE_AT' => $targetStartAt,
			'CREATED_AT' => $now,
		];

		$merge = $helper->prepareMerge(
			RecordIntentStateTable::getTableName(),
			['USER_ID'],
			$insertData,
			$periodFields,
		);

		if (($merge[0] ?? '') === '')
		{
			return $result->addError(new Error('Error constructing merge query'));
		}

		$connection->query($merge[0]);

		$state = $this->getByUserId($userId);
		if ($state !== null)
		{
			$result->setData($state);
		}

		return $result;
	}

	/**
	 * Atomically registers a show. Increments SHOW_COUNT only while below maxShows and the period
	 * still matches; on reaching the limit applies suppression until the period end.
	 */
	public function registerShow(
		int $userId,
		string $periodKey,
		int $maxShows,
		int $periodEndAt,
	): Result
	{
		$result = new Result();

		$connection = Application::getConnection();
		$helper = $connection->getSqlHelper();

		$table = $helper->quote(RecordIntentStateTable::getTableName());
		$userId = (int)$userId;
		$maxShows = (int)$maxShows;
		$periodKeySql = "'" . $helper->forSql($periodKey, 32) . "'";
		$nowUtc = time();

		// No other queries may run between queryExecute and getAffectedRowsCount():
		// affected-rows would otherwise refer to the last executed query, not this UPDATE.
		$connection->queryExecute(
			"UPDATE {$table}"
			. " SET SHOW_COUNT = SHOW_COUNT + 1, LAST_REGISTERED_AT = {$nowUtc}, LAST_SHOWN_AT = {$nowUtc}"
			. " WHERE USER_ID = {$userId} AND PERIOD_KEY = {$periodKeySql} AND SHOW_COUNT < {$maxShows}"
		);

		if ($connection->getAffectedRowsCount() === 0)
		{
			return $result->addError(new Error(self::ERROR_PERIOD_MISMATCH_OR_LIMIT, self::ERROR_PERIOD_MISMATCH_OR_LIMIT));
		}

		$state = $this->getByUserId($userId);

		if ($state !== null && (int)$state['SHOW_COUNT'] >= $maxShows)
		{
			$periodEndAt = (int)$periodEndAt;
			$connection->queryExecute(
				"UPDATE {$table}"
				. " SET SUPPRESSED_UNTIL = {$periodEndAt}"
				. " WHERE USER_ID = {$userId} AND PERIOD_KEY = {$periodKeySql} AND SHOW_COUNT >= {$maxShows}"
			);

			$state = $this->getByUserId($userId);
		}

		if ($state !== null)
		{
			$result->setData($state);
		}

		return $result;
	}

	/**
	 * Atomically registers a period outcome.
	 * 'targetAction' marks the target action done and suppresses the rest of the period.
	 * 'dismiss' increments DISMISS_COUNT only while below maxDismisses and no target action happened;
	 * on reaching the limit applies suppression until the period end.
	 */
	public function registerOutcome(
		int $userId,
		string $periodKey,
		string $outcome,
		int $maxDismisses,
		int $periodEndAt,
	): Result
	{
		$result = new Result();

		$connection = Application::getConnection();
		$helper = $connection->getSqlHelper();

		$table = $helper->quote(RecordIntentStateTable::getTableName());
		$userId = (int)$userId;
		$maxDismisses = (int)$maxDismisses;
		$periodEndAt = (int)$periodEndAt;
		$periodKeySql = "'" . $helper->forSql($periodKey, 32) . "'";
		$nowUtc = time();

		if ($outcome === 'targetAction')
		{
			$connection->queryExecute(
				"UPDATE {$table}"
				. " SET HAS_TARGET_ACTION = 'Y', SUPPRESSED_UNTIL = {$periodEndAt}, LAST_REGISTERED_AT = {$nowUtc}"
				. " WHERE USER_ID = {$userId} AND PERIOD_KEY = {$periodKeySql}"
			);

			// A PERIOD_KEY miss (stale periodKey from the client) updates nothing and is treated
			// as a success no-op here per ALG-02: a target action that already happened must not
			// fail just because its period has rolled over — the client re-reads getData.
			$state = $this->getByUserId($userId);
			if ($state !== null)
			{
				$result->setData($state);
			}

			return $result;
		}

		$connection->queryExecute(
			"UPDATE {$table}"
			. " SET DISMISS_COUNT = DISMISS_COUNT + 1, LAST_REGISTERED_AT = {$nowUtc}"
			. " WHERE USER_ID = {$userId} AND PERIOD_KEY = {$periodKeySql}"
			. " AND DISMISS_COUNT < {$maxDismisses} AND HAS_TARGET_ACTION = 'N'"
		);

		if ($connection->getAffectedRowsCount() === 0)
		{
			return $result->addError(new Error(self::ERROR_PERIOD_MISMATCH_OR_LIMIT, self::ERROR_PERIOD_MISMATCH_OR_LIMIT));
		}

		$state = $this->getByUserId($userId);

		if ($state !== null && (int)$state['DISMISS_COUNT'] >= $maxDismisses)
		{
			$connection->queryExecute(
				"UPDATE {$table}"
				. " SET SUPPRESSED_UNTIL = {$periodEndAt}"
				. " WHERE USER_ID = {$userId} AND PERIOD_KEY = {$periodKeySql}"
			);

			$state = $this->getByUserId($userId);
		}

		if ($state !== null)
		{
			$result->setData($state);
		}

		return $result;
	}

	/**
	 * Suppresses the show window until $suppressedUntil without registering a show or an outcome:
	 * the box is not applicable at all, so the conditions behind it must not be re-evaluated on
	 * every read. The caller owns the bound, because it is the scope of its own verdict and does
	 * not have to coincide with PERIOD_END_AT. The bound only moves forward: a period already
	 * suppressed further ahead keeps its own bound and stays a success. A stale PERIOD_KEY
	 * suppresses nothing and fails, so the caller can tell a closed window from an untouched one.
	 */
	public function suppressPeriod(int $userId, string $periodKey, int $suppressedUntil): Result
	{
		$result = new Result();

		$connection = Application::getConnection();
		$helper = $connection->getSqlHelper();

		$table = $helper->quote(RecordIntentStateTable::getTableName());
		$userId = (int)$userId;
		$suppressedUntil = (int)$suppressedUntil;
		$periodKeySql = "'" . $helper->forSql($periodKey, 32) . "'";
		$nowUtc = time();

		$connection->queryExecute(
			"UPDATE {$table}"
			. " SET SUPPRESSED_UNTIL = {$suppressedUntil}, LAST_REGISTERED_AT = {$nowUtc}"
			. " WHERE USER_ID = {$userId} AND PERIOD_KEY = {$periodKeySql}"
			. " AND SUPPRESSED_UNTIL < {$suppressedUntil}"
		);

		// Affected rows are not the signal here, unlike registerShow/registerOutcome: the bound is
		// monotonic, so a period suppressed further ahead updates nothing and is still suppressed.
		// What decides is the state the period ended up in.
		$state = $this->getByUserId($userId);
		$isWindowClosed =
			$state !== null
			&& $state['PERIOD_KEY'] === $periodKey
			&& $state['SUPPRESSED_UNTIL'] > $nowUtc
		;

		if (!$isWindowClosed)
		{
			return $result->addError(new Error(self::ERROR_PERIOD_MISMATCH_OR_LIMIT, self::ERROR_PERIOD_MISMATCH_OR_LIMIT));
		}

		return $result->setData($state);
	}

	private function normalizeRow(array $row): array
	{
		return [
			'ID' => (int)($row['ID'] ?? 0),
			'USER_ID' => (int)($row['USER_ID'] ?? 0),
			'FIRST_USE_AT' => (int)($row['FIRST_USE_AT'] ?? 0),
			'PERIOD_KEY' => (string)($row['PERIOD_KEY'] ?? ''),
			'TARGET_START_AT' => (int)($row['TARGET_START_AT'] ?? 0),
			'PERIOD_END_AT' => (int)($row['PERIOD_END_AT'] ?? 0),
			'SHOW_COUNT' => (int)($row['SHOW_COUNT'] ?? 0),
			'DISMISS_COUNT' => (int)($row['DISMISS_COUNT'] ?? 0),
			'SUPPRESSED_UNTIL' => (int)($row['SUPPRESSED_UNTIL'] ?? 0),
			'HAS_TARGET_ACTION' => (string)($row['HAS_TARGET_ACTION'] ?? 'N'),
			'LAST_REGISTERED_AT' => (int)($row['LAST_REGISTERED_AT'] ?? 0),
			'LAST_SHOWN_AT' => (int)($row['LAST_SHOWN_AT'] ?? 0),
		];
	}
}
