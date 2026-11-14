<?php

declare(strict_types=1);

namespace Bitrix\Call\Update;

use Bitrix\Call\Internals\OwnerTerm;
use Bitrix\Call\Logger\Logger;
use Bitrix\Main\Application;
use Bitrix\Main\Config\Option;
use Bitrix\Main\DB\Connection;
use Bitrix\Main\Loader;
use Bitrix\Main\Update\Stepper;

/**
 * Writes the owner into the rows of the search table of the journal that were indexed before the table kept one.
 *
 * The owner lives inside the row, in a column and as a reserved word of the searchable content
 * ({@see OwnerTerm}), which makes it a condition of the fulltext pass rather than a filter over its answer.
 * Rows written since the update carry both ({@see \Bitrix\Call\Model\CallUserLogTable::prepareParamsForIndex()});
 * the rows a portal had already collected carry neither, and they are filled once, here.
 *
 * @internal
 */
final class CallUserLogOwnerBackfill extends Stepper
{
	/**
	 * The bookkeeping of the pass: whether it has walked the search table to the end. The option decides one thing,
	 * whether the pass is attached - the updater and the installer (installSearchOwnerPass() in install/index.php)
	 * attach it until the option says filled, and the pass writes the filled state once nothing is left to fill.
	 *
	 * The shape of the search is not chosen by it. The search asks the rows themselves
	 * ({@see \Bitrix\Call\Service\CallLog\JournalIndexSearch::isOwnerKnownForEveryIndexedRow()}): the option and
	 * the data part ways after an update stopped before the pass, a table restored from an older copy or a row
	 * written by code that knows of no owner.
	 */
	public const OPTION_FILLED = 'call_userlog_index_owner_filled';
	public const STATE_PENDING = 'N';
	public const STATE_FILLED = 'Y';

	/**
	 * Width of one window of ids. Walked by the primary key rather than by an offset: the search table is written
	 * to while the pass runs, and an offset would skip rows.
	 */
	private const WINDOW = 5000;

	/**
	 * Seconds one run keeps taking windows, below the time at which Stepper starts spacing runs out
	 * {@see Stepper::THRESHOLD_TIME}.
	 */
	private const TIME_BUDGET = 10.0;

	/** Seconds between two looks at the owner column while the search table has none. */
	private const WAIT = 300;

	/**
	 * How many times the walk may be sent back out past the bound it froze. A cause of a fillable row left at the
	 * bound can last - a portal writing faster than the windows walk, a row the question does not know about -
	 * and a pass that asked for another run for as long as it lasted would never end.
	 */
	private const EXTENSION_LIMIT = 3;

	private const SEARCH_TABLE = 'b_call_userlog_index';
	private const JOURNAL_TABLE = 'b_call_userlog';

	protected static $moduleId = 'call';

	private int $windowSize;
	private float $timeBudget;
	private \Closure $hasOwnerColumn;

	/**
	 * @param (\Closure(Connection, string): bool)|null $hasOwnerColumn whether the search table has the owner
	 *     column yet; the table itself is asked unless an answer is handed in {@see self::tableHasOwnerColumn()}
	 */
	public function __construct(
		int $windowSize = self::WINDOW,
		float $timeBudget = self::TIME_BUDGET,
		?\Closure $hasOwnerColumn = null,
	)
	{
		$this->windowSize = $windowSize;
		$this->timeBudget = $timeBudget;
		$this->hasOwnerColumn = $hasOwnerColumn ?? self::tableHasOwnerColumn(...);
	}

	public function execute(array &$option): bool
	{
		if (!Loader::includeModule('call'))
		{
			// Said out loud: the pass asks to be run again, and an option that never changes is the only other
			// trace of a module that never becomes loadable.
			Logger::getInstance()->error(
				'CallUserLogOwnerBackfill: module call is not available, the search table stays unfilled'
			);

			return self::CONTINUE_EXECUTION;
		}

		// Without the journal there is no owner to read, and a portal that holds neither table has nothing to fill.
		$connection = Application::getConnection();
		if (!$connection->isTableExists(self::SEARCH_TABLE) || !$connection->isTableExists(self::JOURNAL_TABLE))
		{
			return $this->finish($option);
		}

		// The column comes before the pass: the update and the installer add it first. The pass waits for it all
		// the same, since nothing can be filled or frozen before it.
		if (!($this->hasOwnerColumn)($connection, self::SEARCH_TABLE))
		{
			return $this->putTheNextRunOff();
		}

		// Both bounds are frozen on the first run. Rows written later carry their owner already, and the oldest row
		// is where the walk starts, so ids the cleaner has emptied cost no windows. Rows that come out above the
		// bound all the same are gone back for at the end {@see self::endOrExtendTheWalk()}.
		$option['maxId'] ??= (int)$connection->queryScalar('SELECT MAX(USERLOG_ID) FROM ' . self::SEARCH_TABLE);
		$maxId = (int)$option['maxId'];
		if ($maxId <= 0)
		{
			return $this->finish($option);
		}

		$option['baseId'] ??= max(
			0,
			(int)$connection->queryScalar('SELECT MIN(USERLOG_ID) FROM ' . self::SEARCH_TABLE) - 1,
		);
		$baseId = (int)$option['baseId'];
		$lastId = (int)($option['lastId'] ?? $baseId);
		$option['count'] = $this->countWindows($baseId, $maxId);

		$deadline = microtime(true) + $this->timeBudget;
		do
		{
			$upperId = min($lastId + $this->windowSize, $maxId);
			$option['rewritten'] = (int)($option['rewritten'] ?? 0) + $this->fillWindow($lastId, $upperId);

			$lastId = $upperId;
			$option['lastId'] = $lastId;
			$option['steps'] = $this->countWindows($baseId, $lastId);
		}
		while ($lastId < $maxId && microtime(true) < $deadline);

		if ($lastId >= $maxId)
		{
			return $this->endOrExtendTheWalk($option);
		}

		return self::CONTINUE_EXECUTION;
	}

	/**
	 * Ends the walk, or sends it back out: the data is ready when nothing is left to fill, not when the bound is
	 * reached.
	 *
	 * The updater runs before the files of the module are copied, and the bound is taken by the first run of the
	 * pass a minute later at the earliest. A process that loaded the old table class goes on writing rows without
	 * an owner meanwhile; one written after the bound lies above it, and one committed after the window over its
	 * id lies behind the cursor. The walk goes back for both, a bounded number of times.
	 *
	 * The question is whether a row without an owner still has its journal row. An orphan can never be filled -
	 * the window fills by joining the journal - so a pass that went back out for orphans would never end.
	 */
	private function endOrExtendTheWalk(array &$option): bool
	{
		$lowestFillableId = $this->lowestFillableRow();
		if ($lowestFillableId === null)
		{
			return $this->finish($option);
		}

		$extension = (int)($option['extensions'] ?? 0) + 1;
		if ($extension > self::EXTENSION_LIMIT)
		{
			Logger::getInstance()->error(sprintf(
				'CallUserLogOwnerBackfill: rows from id %d on are still to be filled after %d extension(s) of the '
				. 'walk, the pass ends leaving them as they are',
				$lowestFillableId,
				self::EXTENSION_LIMIT,
			));

			return $this->finish($option);
		}

		$connection = Application::getConnection();
		$option['extensions'] = $extension;
		$option['maxId'] = (int)$connection->queryScalar('SELECT MAX(USERLOG_ID) FROM ' . self::SEARCH_TABLE);
		$option['lastId'] = min((int)($option['lastId'] ?? 0), $lowestFillableId - 1);

		// The windows the walk goes back over are steps of the pass: until they are walked, the progress of the
		// update must not read as finished.
		$baseId = (int)($option['baseId'] ?? 0);
		$option['count'] = $this->countWindows($baseId, (int)$option['maxId']);
		$option['steps'] = $this->countWindows($baseId, (int)$option['lastId']);

		Logger::getInstance()->info(sprintf(
			'CallUserLogOwnerBackfill: extension %d of %d, the walk goes on from id %d to id %d '
			. 'over rows from id %d on',
			$extension,
			self::EXTENSION_LIMIT,
			(int)$option['lastId'],
			(int)$option['maxId'],
			$lowestFillableId,
		));

		return self::CONTINUE_EXECUTION;
	}

	/**
	 * The lowest row of the search table that names no owner and has a journal row to take one from, or null.
	 * The index over (USER_ID, USERLOG_ID) answers the first half, the primary key of the journal the second.
	 */
	private function lowestFillableRow(): ?int
	{
		$lowest = Application::getConnection()->queryScalar(
			'SELECT MIN(i.USERLOG_ID)
				FROM ' . self::SEARCH_TABLE . ' i
				INNER JOIN ' . self::JOURNAL_TABLE . ' l ON l.ID = i.USERLOG_ID
				WHERE i.USER_ID IS NULL'
		);

		return $lowest === null ? null : (int)$lowest;
	}

	/**
	 * Gives the rows of one window the owner their journal row names, in the column and in the content at once.
	 *
	 * Only a row whose column is still empty is touched. The content is appended to, so that one condition is
	 * what keeps a second run from writing the term twice, and what leaves alone a row the write path has brought
	 * up to date meanwhile. A row whose journal row is gone is matched by nothing and stays empty.
	 *
	 * The term is concatenated out of the two stored halves of its format and the column, the one form of it with
	 * no PHP to call; that it equals {@see OwnerTerm::stored()} is held by CallLogOwnerTermTest. A row that held
	 * nothing comes out carrying the term alone, with no separator in front, as the write path writes it.
	 *
	 * @return int rows the window rewrote
	 */
	private function fillWindow(int $fromId, int $toId): int
	{
		$connection = Application::getConnection();
		$sqlHelper = $connection->getSqlHelper();

		$prefix = $sqlHelper->convertToDbString(OwnerTerm::STORED_PREFIX);
		$terminator = $sqlHelper->convertToDbString(OwnerTerm::STORED_TERMINATOR);
		$window = "i.USERLOG_ID > {$fromId} AND i.USERLOG_ID <= {$toId} AND i.USER_ID IS NULL";
		$searchTable = self::SEARCH_TABLE;
		$journal = self::JOURNAL_TABLE;
		$content = "NULLIF(i.SEARCH_CONTENT, '')";

		$connection->queryExecute($connection->getType() === 'pgsql'
			? "UPDATE {$searchTable} i
				SET USER_ID = l.USER_ID,
					SEARCH_CONTENT = CONCAT_WS(' ', {$content}, {$prefix} || l.USER_ID || {$terminator})
				FROM {$journal} l
				WHERE l.ID = i.USERLOG_ID AND {$window}"
			: "UPDATE {$searchTable} i
				INNER JOIN {$journal} l ON l.ID = i.USERLOG_ID
				SET i.USER_ID = l.USER_ID,
					i.SEARCH_CONTENT = CONCAT_WS(' ', {$content}, CONCAT({$prefix}, l.USER_ID, {$terminator}))
				WHERE {$window}"
		);

		return (int)$connection->getAffectedRowsCount();
	}

	/**
	 * Asks the table rather than the fields the connection keeps from an earlier read in this process: the column
	 * may have been added since.
	 */
	private static function tableHasOwnerColumn(Connection $connection, string $table): bool
	{
		$connection->clearCaches($table);

		return $connection->getTableField($table, 'USER_ID') !== null;
	}

	/**
	 * Puts the next run off instead of coming back on every hit while the column is not there. The agent runner
	 * takes the next run from this global {@see \CAgent::ExecuteAgents()}, and Stepper keeps it unless the run
	 * outlasts its threshold {@see Stepper::execAgent()}.
	 */
	private function putTheNextRunOff(): bool
	{
		global $pPERIOD;
		$pPERIOD = self::WAIT;

		return self::CONTINUE_EXECUTION;
	}

	private function countWindows(int $fromId, int $toId): int
	{
		return (int)ceil(($toId - $fromId) / $this->windowSize);
	}

	/**
	 * Announces the data ready and ends the pass. Rewriting rows of a fulltext table leaves their old documents
	 * behind, but the pass does not rebuild the table: a rebuild holds every write to it for its whole length.
	 */
	private function finish(array &$option): bool
	{
		Option::set('call', self::OPTION_FILLED, self::STATE_FILLED);

		$option['steps'] = (int)($option['count'] ?? 0);

		// The one event worth finding afterwards: the search relies on the pass having got this far, and a pass
		// that stopped halfway looks the same from the outside as one that never started.
		Logger::getInstance()->info(sprintf(
			'CallUserLogOwnerBackfill: the search table is filled up to id %d, %d row(s) rewritten',
			(int)($option['lastId'] ?? 0),
			(int)($option['rewritten'] ?? 0),
		));

		return self::FINISH_EXECUTION;
	}
}
