<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internals\Steppers;

use Bitrix\Disk\Internals\ObjectTable;
use Bitrix\Disk\Internals\UniqueCode;
use Bitrix\Disk\TypeFile;
use Bitrix\Main\Application;
use Bitrix\Main\DB\SqlException;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Main\SystemException;
use Bitrix\Main\Update\Stepper;

/**
 * Gives the code of the unified link to the real objects that have none and are not covered by
 * UniqueCodeUpdater: folders and files of any type except documents, PDF and boards. Trashed objects
 * are included. Reading an object never writes its code, so until this stepper reaches an object
 * the object keeps the legacy link.
 */
class UnifiedLinkUniqueCodeBackfillStepper extends Stepper
{
	public const ROWS_PER_STEP = 1000;
	/**
	 * The range of ID one step looks through. A step reads at most this many rows of the primary key whatever
	 * the share of the candidates, and writes at most ROWS_PER_STEP of them; the range costs milliseconds and
	 * keeps the number of steps at MAX(ID) / ID_WINDOW where the candidates are sparse. A range of ID without any row
	 * costs one step whatever its length: the cursor jumps to the next row.
	 */
	public const ID_WINDOW = 10000;
	/**
	 * The row-by-row retry of a failed portion stops after this many failed rows in a row: the cause is likely
	 * not in the rows, so the portion is left to the next run.
	 */
	public const MAX_CONSECUTIVE_ROW_FAILURES = 3;
	/**
	 * The runs in a row stopped on the same portion before the portion is skipped: a failure that outlives them
	 * would otherwise hold the stepper on one place for good. The skipped rows keep the legacy link.
	 */
	public const MAX_INTERRUPTED_RUNS = 3;
	/** The types with steppers of their own (UniqueCodeUpdater and the boards). */
	protected const COVERED_FILE_TYPES = [
		TypeFile::DOCUMENT,
		TypeFile::PDF,
		TypeFile::FLIPCHART,
	];

	protected static $moduleId = 'disk';

	/**
	 * @inheritDoc
	 */
	public function execute(array &$option): bool
	{
		$lastProcessedId = (int)($option['lastProcessedId'] ?? 0);
		$option['steps'] = (int)($option['steps'] ?? 0);
		if (!isset($option['maxId']))
		{
			// the objects added later are given the code on creation
			$option['maxId'] = $this->fetchMaxId();
		}
		$maxId = (int)$option['maxId'];

		if ($lastProcessedId >= $maxId)
		{
			return self::FINISH_EXECUTION;
		}

		$windowEndId = min($lastProcessedId + static::ID_WINDOW, $maxId);
		$ids = $this->fetchCandidateIds($lastProcessedId, $windowEndId);
		if (empty($ids))
		{
			$nextId = $this->fetchNextId($lastProcessedId);
			if ($nextId === null || $nextId > $maxId)
			{
				$option['lastProcessedId'] = $maxId;

				return self::FINISH_EXECUTION;
			}

			if ($nextId > $windowEndId)
			{
				// a gap of ID without rows: the next step starts at the next row instead of walking the empty windows
				unset($option['interruptedRuns']);
				$option['lastProcessedId'] = $nextId - 1;

				return self::CONTINUE_EXECUTION;
			}
		}

		// a full portion may leave candidates in the rest of the window: the next step goes on from the portion
		$processedId = count($ids) >= static::ROWS_PER_STEP ? max($ids) : $windowEndId;

		if (!empty($ids))
		{
			[$written, $isInterrupted] = $this->fillCodes($ids);
			$option['steps'] += $written;
			if ($isInterrupted)
			{
				$option['interruptedRuns'] = (int)($option['interruptedRuns'] ?? 0) + 1;
				if ($option['interruptedRuns'] < static::MAX_INTERRUPTED_RUNS)
				{
					// the cursor stays: the rows written so far already have the code and are not met again
					return self::CONTINUE_EXECUTION;
				}

				$this->writeToLog(new SystemException(
					"Unified link code backfill skipped objects after {$lastProcessedId} up to {$processedId}"
					. " after {$option['interruptedRuns']} interrupted runs",
				));
			}
		}

		unset($option['interruptedRuns']);
		$option['lastProcessedId'] = $processedId;

		return $processedId >= $maxId ? self::FINISH_EXECUTION : self::CONTINUE_EXECUTION;
	}

	protected function fetchMaxId(): int
	{
		return (int)Application::getConnection()->queryScalar('SELECT MAX(ID) FROM b_disk_object');
	}

	/**
	 * The first ID past $lastProcessedId of any row, a candidate or not; the primary key answers it.
	 */
	protected function fetchNextId(int $lastProcessedId): ?int
	{
		$nextId = Application::getConnection()->queryScalar(
			'SELECT MIN(ID) FROM b_disk_object WHERE ID > ' . $lastProcessedId
		);

		return $nextId === null ? null : (int)$nextId;
	}

	/**
	 * @return int[]
	 */
	protected function fetchCandidateIds(int $lastProcessedId, int $windowEndId): array
	{
		// the query API renders NULL checks as IS NULL only: the legacy filter adds "= ''", which an
		// integer column of PostgreSQL rejects
		$rows = ObjectTable::query()
			->setSelect(['ID'])
			->where('ID', '>', $lastProcessedId)
			->where('ID', '<=', $windowEndId)
			->whereColumn('REAL_OBJECT_ID', 'ID')
			->where(
				Query::filter()
					->logic('or')
					->whereNull('UNIQUE_CODE')
					->where('UNIQUE_CODE', '')
			)
			->where(
				Query::filter()
					->logic('or')
					->where('TYPE', ObjectTable::TYPE_FOLDER)
					->whereNull('TYPE_FILE')
					->whereNotIn('TYPE_FILE', self::COVERED_FILE_TYPES)
			)
			->setOrder(['ID' => 'ASC'])
			->setLimit(static::ROWS_PER_STEP)
			->exec()
		;

		$ids = [];
		while ($row = $rows->fetch())
		{
			$ids[] = (int)$row['ID'];
		}

		return $ids;
	}

	/**
	 * When the portion fails, every row is tried once more on its own with a fresh code; a row that fails
	 * again is logged and skipped. After MAX_CONSECUTIVE_ROW_FAILURES failed rows in a row the retry stops
	 * and the portion is reported as interrupted.
	 *
	 * @param int[] $ids
	 * @return array{0: int, 1: bool} the number of the rows written and whether the retry was interrupted
	 */
	protected function fillCodes(array $ids): array
	{
		$codes = $this->generateCodes($ids);
		try
		{
			return [$this->updateCodes($codes), false];
		}
		catch (SqlException $exception)
		{
			$this->writeToLog($exception);
		}

		$written = 0;
		$consecutiveFailures = 0;
		foreach ($this->generateCodes(array_keys($codes)) as $id => $code)
		{
			try
			{
				$written += $this->updateCodes([$id => $code]);
				$consecutiveFailures = 0;
			}
			catch (SqlException $exception)
			{
				$this->writeToLog(new SystemException(
					"Unified link code backfill skipped object {$id}: " . $exception->getMessage(),
					previous: $exception,
				));

				if (++$consecutiveFailures >= static::MAX_CONSECUTIVE_ROW_FAILURES)
				{
					return [$written, true];
				}
			}
		}

		return [$written, false];
	}

	/**
	 * Codes not taken by any object at the moment of the check; the unique index keeps the rest.
	 *
	 * @param int[] $ids
	 * @return array<int, string>
	 */
	protected function generateCodes(array $ids): array
	{
		$codes = [];
		$givenCodes = [];
		$uniqueCode = new UniqueCode();

		while (!empty($ids))
		{
			$candidateCodes = [];
			foreach ($ids as $id)
			{
				$candidateCodes[$id] = $uniqueCode->generate();
			}

			$existingCodes = [];
			$rows = ObjectTable::getList([
				'select' => ['UNIQUE_CODE'],
				'filter' => ['@UNIQUE_CODE' => array_values($candidateCodes)],
			]);
			while ($row = $rows->fetch())
			{
				$existingCodes[$row['UNIQUE_CODE']] = true;
			}

			$ids = [];
			foreach ($candidateCodes as $id => $code)
			{
				if (isset($existingCodes[$code]) || isset($givenCodes[$code]))
				{
					$ids[] = $id;
				}
				else
				{
					$codes[$id] = $code;
					$givenCodes[$code] = true;
				}
			}
		}

		return $codes;
	}

	/**
	 * A code another writer gave in the meantime is kept: only the rows still without a code are changed.
	 *
	 * @param array<int, string> $codes
	 * @return int the number of the rows changed
	 */
	protected function updateCodes(array $codes): int
	{
		if (empty($codes))
		{
			return 0;
		}

		$connection = Application::getConnection();
		$sqlHelper = $connection->getSqlHelper();

		$caseSql = '';
		foreach ($codes as $id => $code)
		{
			$caseSql .= 'WHEN ' . (int)$id . " THEN '" . $sqlHelper->forSql($code) . "' ";
		}
		$idList = implode(',', array_map('intval', array_keys($codes)));

		$connection->queryExecute("
			UPDATE b_disk_object
			SET UNIQUE_CODE = CASE ID {$caseSql}END
			WHERE ID IN ({$idList}) AND (UNIQUE_CODE IS NULL OR UNIQUE_CODE = '')
		");

		return (int)$connection->getAffectedRowsCount();
	}
}
