<?php

declare(strict_types=1);

namespace Bitrix\Call\Update;

use Bitrix\Call\Logger\Logger;
use Bitrix\Call\Model\CallTable;
use Bitrix\Call\Model\CallUserLogTable;
use Bitrix\Call\Service\CallLogDirectionResolver;
use Bitrix\Main\Application;
use Bitrix\Main\Config\Option;
use Bitrix\Main\DB\Connection;
use Bitrix\Main\Loader;
use Bitrix\Main\Update\Stepper;
use Bitrix\Voximplant\StatisticTable;

/**
 * Writes the side of the call into the journal rows collected before the journal kept one.
 *
 * The live write gives a row its side once, while the row has none, and a finished row gets no more
 * events. Without this pass the rows collected before the column would stay without a side for good.
 * The pass fills only the rows that have none, by the rules the live write follows, so a row written
 * meanwhile keeps the side it was given.
 *
 * @internal
 */
final class CallUserLogDirectionBackfill extends Stepper
{
	/**
	 * Whether the journal is filled: absent or pending means rows without a side may still exist.
	 */
	public const OPTION_FILLED = 'call_userlog_direction_filled';
	public const STATE_PENDING = 'N';
	public const STATE_FILLED = 'Y';

	/**
	 * Width of one window of ids. Walked by the primary key rather than by an offset: the journal is
	 * written to while the pass runs, and an offset would skip rows.
	 */
	private const WINDOW = 5000;

	/**
	 * Seconds one run keeps taking windows, below the time at which Stepper starts spacing runs out
	 * {@see Stepper::THRESHOLD_TIME}.
	 */
	private const TIME_BUDGET = 10.0;

	/**
	 * Seconds between two looks at what the pass waits for: the end of the im -> call migration, the column
	 * of the side in the journal.
	 */
	private const WAIT = 300;

	/**
	 * The statuses the journal knows. Which of them stand for the outgoing side is asked of the resolver
	 * rather than written here {@see self::outgoingStatuses()}.
	 */
	private const STATUSES = [
		CallUserLogTable::STATUS_INITIATED,
		CallUserLogTable::STATUS_ANSWERED,
		CallUserLogTable::STATUS_DECLINED,
		CallUserLogTable::STATUS_MISSED,
	];

	protected static $moduleId = 'call';

	private int $windowSize;
	private float $timeBudget;
	private \Closure $hasSideColumn;

	/**
	 * @param (\Closure(Connection, string): bool)|null $hasSideColumn whether the journal has the column of the
	 *     side yet; the table itself is asked unless an answer is handed in {@see self::tableHasSideColumn()}
	 */
	public function __construct(
		int $windowSize = self::WINDOW,
		float $timeBudget = self::TIME_BUDGET,
		?\Closure $hasSideColumn = null,
	)
	{
		$this->windowSize = $windowSize;
		$this->timeBudget = $timeBudget;
		$this->hasSideColumn = $hasSideColumn ?? self::tableHasSideColumn(...);
	}

	public function execute(array &$option): bool
	{
		if (!Loader::includeModule('call'))
		{
			return self::FINISH_EXECUTION;
		}

		// The migration copies into the table of calls the calls the journal refers to. Until it is over a
		// meeting it has not copied yet has no creator to read, and its rows would be settled by the status
		// for good, so nothing is filled or frozen before it ends.
		if (!MigrationState::isCompleted())
		{
			return $this->putTheNextRunOff();
		}

		$connection = Application::getConnection();
		$journal = CallUserLogTable::getTableName();
		if (!$connection->isTableExists($journal))
		{
			return $this->finish($option);
		}

		// The column comes before the pass: update 26.1100.0 adds it first, and so does the installer to a journal
		// an uninstall kept from a version before it. The pass waits for it all the same: nothing can be filled or
		// frozen before it.
		if (!($this->hasSideColumn)($connection, $journal))
		{
			return $this->putTheNextRunOff();
		}

		// Both bounds are frozen on the first run. Rows written after it carry the side the live write gave
		// them, and the oldest row is where the walk starts, so ids the cleaner has emptied cost no windows.
		$option['maxId'] ??= (int)$connection->queryScalar("SELECT MAX(ID) FROM {$journal}");
		$maxId = (int)$option['maxId'];
		if ($maxId <= 0)
		{
			return $this->finish($option);
		}

		$option['baseId'] ??= max(0, (int)$connection->queryScalar("SELECT MIN(ID) FROM {$journal}") - 1);
		$baseId = (int)$option['baseId'];
		$lastId = (int)($option['lastId'] ?? $baseId);
		$option['count'] = $this->countWindows($baseId, $maxId);

		$deadline = microtime(true) + $this->timeBudget;
		do
		{
			$upperId = min($lastId + $this->windowSize, $maxId);
			$this->fillWindow($lastId, $upperId);

			$lastId = $upperId;
			$option['lastId'] = $lastId;
			$option['steps'] = $this->countWindows($baseId, $lastId);
		}
		while ($lastId < $maxId && microtime(true) < $deadline);

		if ($lastId >= $maxId)
		{
			return $this->finish($option);
		}

		return self::CONTINUE_EXECUTION;
	}

	/**
	 * Fills the rows of the window that have no side yet, from the source that knows the most down to the
	 * status. Each update touches only what the one before it left empty, and the status leaves none.
	 */
	private function fillWindow(int $fromId, int $toId): void
	{
		$connection = Application::getConnection();
		$sqlHelper = $connection->getSqlHelper();
		$isPgsql = $connection->getType() === 'pgsql';

		$journal = CallUserLogTable::getTableName();
		$outgoing = $sqlHelper->convertToDbString(CallUserLogTable::DIRECTION_OUTGOING);
		$incoming = $sqlHelper->convertToDbString(CallUserLogTable::DIRECTION_INCOMING);

		// An empty string is no side either: the tabs of the journal read '=DIRECTION' => null as
		// IS NULL OR = '' (CallLogService::buildDirectionCondition()) and pick such a row by its status.
		$hasNoSide = '(l.DIRECTION IS NULL OR l.DIRECTION = ' . $sqlHelper->convertToDbString('') . ')';
		$window = "l.ID > {$fromId} AND l.ID <= {$toId} AND {$hasNoSide}";

		// A meeting: the creator started it, everybody else was called into it. A call that records no
		// creator is left to the status, as CallLogDirectionResolver::forCallParticipant() leaves it.
		$calls = CallTable::getTableName();
		$byCreator = "CASE WHEN l.USER_ID = s.INITIATOR_ID THEN {$outgoing} ELSE {$incoming} END";
		$isMeeting = 'l.SOURCE_TYPE = ' . $sqlHelper->convertToDbString(CallUserLogTable::SOURCE_TYPE_CALL)
			. ' AND s.INITIATOR_ID > 0'
		;
		$connection->queryExecute($isPgsql
			? "UPDATE {$journal} l SET DIRECTION = {$byCreator}
				FROM {$calls} s
				WHERE s.ID = l.SOURCE_CALL_ID AND {$isMeeting} AND {$window}"
			: "UPDATE {$journal} l
				INNER JOIN {$calls} s ON s.ID = l.SOURCE_CALL_ID
				SET l.DIRECTION = {$byCreator}
				WHERE {$isMeeting} AND {$window}"
		);

		// A phone call: outgoing only for the user the portal placed the call by, the way
		// CallProcessor::resolveDirection() decides it for a fresh row. INCOMING is kept as a string.
		if (Loader::includeModule('voximplant') && $connection->isTableExists(StatisticTable::getTableName()))
		{
			$statistics = StatisticTable::getTableName();
			$placedByPortal = 'v.INCOMING IN ('
				. $sqlHelper->convertToDbString((string)\CVoxImplantMain::CALL_OUTGOING) . ', '
				. $sqlHelper->convertToDbString((string)\CVoxImplantMain::CALL_CALLBACK) . ')'
			;
			$byStatistics = "CASE WHEN {$placedByPortal} AND l.USER_ID = v.PORTAL_USER_ID"
				. " THEN {$outgoing} ELSE {$incoming} END"
			;
			$isPhoneCall = 'l.SOURCE_TYPE = ' . $sqlHelper->convertToDbString(CallUserLogTable::SOURCE_TYPE_VOXIMPLANT);

			$connection->queryExecute($isPgsql
				? "UPDATE {$journal} l SET DIRECTION = {$byStatistics}
					FROM {$statistics} v
					WHERE v.ID = l.SOURCE_CALL_ID AND {$isPhoneCall} AND {$window}"
				: "UPDATE {$journal} l
					INNER JOIN {$statistics} v ON v.ID = l.SOURCE_CALL_ID
					SET l.DIRECTION = {$byStatistics}
					WHERE {$isPhoneCall} AND {$window}"
			);
		}

		// Whatever is left has no call or statistics to ask: the side the status stands for.
		$outgoingStatuses = array_map(
			static fn(string $status): string => $sqlHelper->convertToDbString($status),
			$this->outgoingStatuses(),
		);
		$byStatus = $outgoingStatuses === []
			? $incoming
			: 'CASE WHEN l.STATUS IN (' . implode(', ', $outgoingStatuses) . ") THEN {$outgoing} ELSE {$incoming} END"
		;

		$connection->queryExecute($isPgsql
			? "UPDATE {$journal} l SET DIRECTION = {$byStatus} WHERE {$window}"
			: "UPDATE {$journal} l SET l.DIRECTION = {$byStatus} WHERE {$window}"
		);
	}

	/**
	 * @return string[]
	 */
	private function outgoingStatuses(): array
	{
		$resolver = new CallLogDirectionResolver();

		return array_values(array_filter(
			self::STATUSES,
			static fn(string $status): bool => $resolver->fromStatus($status) === CallUserLogTable::DIRECTION_OUTGOING,
		));
	}

	/**
	 * Asks the table rather than the fields the connection keeps from an earlier read in this process: the
	 * column may have been added since.
	 */
	private static function tableHasSideColumn(Connection $connection, string $journal): bool
	{
		$connection->clearCaches($journal);

		return $connection->getTableField($journal, 'DIRECTION') !== null;
	}

	/**
	 * Puts the next run off instead of coming back on every hit while what the pass waits for is slow or
	 * stuck. The agent runner takes the next run from this global {@see \CAgent::ExecuteAgents()}, and
	 * Stepper keeps it unless the run outlasts its threshold {@see Stepper::execAgent()}.
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

	private function finish(array &$option): bool
	{
		Option::set('call', self::OPTION_FILLED, self::STATE_FILLED);

		$option['steps'] = (int)($option['count'] ?? 0);

		Logger::getInstance()->info(sprintf(
			'CallUserLogDirectionBackfill: the journal is filled up to id %d in %d window(s)',
			(int)($option['lastId'] ?? 0),
			$option['steps'],
		));

		return self::FINISH_EXECUTION;
	}
}
