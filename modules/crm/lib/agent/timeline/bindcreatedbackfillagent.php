<?php

declare(strict_types=1);

namespace Bitrix\Crm\Agent\Timeline;

use Bitrix\Crm\Agent\AgentBase;
use Bitrix\Crm\Service\Container;
use Bitrix\Crm\Timeline\Entity\TimelineBindingTable;
use Bitrix\Main\Application;
use Bitrix\Main\Config\Option;
use Bitrix\Main\DB\Connection;
use Bitrix\Main\DB\SqlExpression;
use Bitrix\Main\DB\SqlHelper;
use Psr\Log\LoggerInterface;

/**
 * One-off backfill of the denormalized b_crm_timeline_bind.CREATED column, moved here from
 * Bitrix\Crm\Update\Timeline\BindCreatedBackfillStepper.
 *
 * The pace is set by the module, not by the kernel: a step works a short window, a mandatory pause separates the
 * steps, and the period until the next run is computed from the actual duration of the step. The switch stops the work
 * without a hotfix and leaves the agent in the schedule. The table is walked by ranges of OWNER_ID, so the duration of
 * a query follows the width of the range instead of the size of the table, and the window of the step is looked at
 * between them. Removing the transitional read option completes the work and switches the timeline history to the
 * optimized read path; a remainder gets exactly one safety pass, then a warning.
 */
final class BindCreatedBackfillAgent extends AgentBase
{
	/** @see self::run() */
	public const AGENT_NAME = self::class . '::run();';

	private const MODULE_ID = 'crm';

	private const OPTION_ENABLED = 'timeline_bind_created_backfill_enabled';
	private const OPTION_STEP_LIMIT = 'timeline_bind_created_backfill_step_limit';
	private const OPTION_TIME_LIMIT = 'timeline_bind_created_backfill_time_limit';
	private const OPTION_INTERVAL = 'timeline_bind_created_backfill_interval';
	private const OPTION_MAX_INTERVAL = 'timeline_bind_created_backfill_max_interval';
	private const OPTION_READ_ENABLED = 'timeline_bind_created_enabled';

	private const DEFAULT_STEP_LIMIT = 100;
	private const DEFAULT_TIME_LIMIT = 1.0;
	private const DEFAULT_INTERVAL = 30;
	private const DEFAULT_MAX_INTERVAL = 300;

	/**
	 * The bounds of the pace. A value outside them is brought to the nearest allowed one instead of being refused: the
	 * options are the only way to slow the backfill down on a portal, and a value that looks like a way to do that has
	 * to behave like one.
	 */
	private const STEP_LIMIT_MIN = 1;
	private const STEP_LIMIT_MAX = 1000;
	private const TIME_LIMIT_MIN = 0.1;
	private const TIME_LIMIT_MAX = 10.0;
	private const INTERVAL_MIN = 10;
	private const INTERVAL_MAX = 3600;

	/** A ceiling below the base interval makes the formula of the period meaningless; a day above it is enough. */
	private const MAX_INTERVAL_MAX = 86400;

	/**
	 * The width of the range of OWNER_ID one turn of the traversal covers, in units of the identifier. The duration of
	 * both select queries is linear in the number of rows the range holds. The lower bound keeps the traversal from
	 * paying the constant part of two queries for every empty stretch of the identifier space, and the ceiling belongs
	 * to the phase, because a row costs the anti-join of the orphans about seven times what it costs the filling.
	 */
	private const RANGE_WIDTH_DEFAULT = 10000;
	private const RANGE_WIDTH_MIN = 1000;
	private const RANGE_WIDTH_MAX_BACKFILL = 100000;
	private const RANGE_WIDTH_MAX_ORPHANS = 12500;

	/**
	 * The duration of a select query the width answers to: below the first it doubles, above the second it comes
	 * down in proportion to how far above it went.
	 */
	private const RANGE_QUERY_FAST = 0.025;
	private const RANGE_QUERY_SLOW = 0.05;

	/** The delay of register(); the Migration API gives as much to a cloud portal and less in a box. */
	private const FIRST_RUN_DELAY = 600;

	/**
	 * The state of the pass lives in its own category as a single record: writing to a category drops the cache of
	 * the whole category, and the isolation keeps that away from the hot option cache of the module.
	 */
	private const STATE_MODULE_ID = 'crm.timeline_bind_backfill';
	private const STATE_OPTION_NAME = 'state';

	private const PHASE_BACKFILL = 'backfill';
	private const PHASE_ORPHANS = 'orphans';

	private const SAFETY_PASS_LIMIT = 1;

	/**
	 * The index the optimized read path of the history rests on. It is created by the same update that sets the
	 * transitional option, so a portal that has the option without the index has had that migration interrupted:
	 * removing the option there would send the history to a query with nothing behind it.
	 */
	private const READ_PATH_INDEX = 'IX_B_CRM_TIMELINE_BIND_3';
	private const READ_PATH_INDEX_COLUMNS = ['ENTITY_ID', 'ENTITY_TYPE_ID', 'CREATED', 'OWNER_ID'];

	private const LOGGER_CHANNEL = 'Agent';

	private const AGENT_CONTINUE = true;
	private const AGENT_STOP = false;

	/** null until the pass knows where the key begins: a cursor of its own is what a resumed pass has instead. */
	private ?int $lastOwner = null;
	private int $lastEntity = 0;
	private int $lastType = 0;
	private ?int $lastOrphanOwner = null;
	private string $phase = self::PHASE_BACKFILL;
	private int $lastStepAt = 0;
	private int $passCount = 0;

	/** null until the pass has taken the edges; a record without the fields is a record of the previous format. */
	private ?int $passLowBound = null;
	private ?int $passBound = null;
	private int $rangeFrom = 0;
	private int $rangeTo = 0;
	private int $orphanRangeTo = 0;
	/**
	 * Whether the bounds above belong to a range that has been opened. A bound is a value of OWNER_ID, and zero is
	 * one of its allowed values, so being open is a fact of its own and cannot be read off the bound.
	 */
	private bool $rangeOpen = false;
	private bool $orphanRangeOpen = false;
	private int $rangeWidth = self::RANGE_WIDTH_DEFAULT;
	private int $remainder = 0;

	private readonly \Closure $clock;
	private readonly LoggerInterface $logger;

	/**
	 * @param null|\Closure(): float $clock unix timestamp with microseconds
	 */
	public function __construct(?\Closure $clock = null, ?LoggerInterface $logger = null)
	{
		$this->clock = $clock ?? static fn(): float => microtime(true);
		$this->logger = $logger ?? Container::getInstance()->getLogger(self::LOGGER_CHANNEL);
	}

	public static function doRun(): bool
	{
		return (new self())->execute();
	}

	/**
	 * Puts the agent on the schedule of the portal, and does nothing if the record is already there. Used by the
	 * transitional stepper and by the installation of the module; the updater of an open update asks isRegistered()
	 * and adds the agent through the Migration API instead.
	 *
	 * The record is deliberately not periodic: for a non-periodic record \CAgent counts the next run from the end of
	 * the step, and the pace of the agent relies on that, while a periodic one counts it from the previous run.
	 *
	 * Two ways of delivery can reach the schedule between the lookup and the addition, and an existing record is the
	 * outcome asked for, so the addition is told not to report it as an error.
	 */
	public static function register(): void
	{
		if (self::isRegistered())
		{
			return;
		}

		\CAgent::AddAgent(
			self::AGENT_NAME,
			self::MODULE_ID,
			'N',
			self::getInterval(),
			'',
			'Y',
			\ConvertTimeStamp(time() + \CTimeZone::GetOffset() + self::FIRST_RUN_DELAY, 'FULL'),
			existError: false,
		);
	}

	/**
	 * Whether the schedule of the portal already holds the record of the agent. The name is compared exactly - 'NAME'
	 * would be a LIKE pattern - and the activity of the record is left out of the filter on purpose: an inactive
	 * record occupies the name all the same, and a second one under it cannot be added.
	 */
	public static function isRegistered(): bool
	{
		$record = \CAgent::GetList(
			[],
			[
				'=NAME' => self::AGENT_NAME,
				'MODULE_ID' => self::MODULE_ID,
			],
		)->Fetch();

		return $record !== false;
	}

	public function execute(): bool
	{
		$interval = self::getInterval();

		if (!$this->isBackfillPending())
		{
			// the backfill is over or was never needed, and the table is not touched to find that out; the state is
			// normally gone already, and nobody would clean it up once the agent is off the schedule. Asked before the
			// switch, so that a portal that has the backfill switched off still gets its agent removed
			$this->deleteState();

			return self::AGENT_STOP;
		}

		if (!$this->isEnabled())
		{
			// the switch pauses the backfill, it does not cancel it: the agent waits in the schedule
			$this->setExecutionPeriod($interval);

			return self::AGENT_CONTINUE;
		}

		$passIsNew = !$this->loadState();

		if ($this->now() - $this->lastStepAt < $interval)
		{
			// not enough time has passed since the previous step, no data work at all; the period is set all the
			// same, so raising the interval on the portal thins out the empty runs together with the working ones
			$this->setExecutionPeriod($interval);

			return self::AGENT_CONTINUE;
		}

		$stepWindow = $this->getStepWindow();
		$portionLimit = $this->getPortionLimit();

		if ($passIsNew)
		{
			$this->reportPassStart($portionLimit, $stepWindow, $interval);
		}

		$startedAt = $this->now();

		try
		{
			$workIsDone = $this->runWorkWindow($startedAt, $stepWindow, $portionLimit);
		}
		finally
		{
			// the direct SQL bypasses the ORM, so the cache of the entity is dropped once per step and on every way
			// out of the window: the rows an interrupted step has updated stay updated
			TimelineBindingTable::cleanCache();
		}

		if ($workIsDone)
		{
			return $this->finalize($interval);
		}

		$this->lastStepAt = (int)$this->now();
		$this->saveState();
		$this->setExecutionPeriod($this->calculateNextPeriod($this->now() - $startedAt, $stepWindow, $interval));

		return self::AGENT_CONTINUE;
	}

	/**
	 * Processes portions until the window of the step is over or the work is exhausted. An empty portion no longer
	 * means the phase is over: it means the range holds nothing, and the next range is where to look.
	 *
	 * @return bool whether nothing is left to do, which is what the finalization is called on
	 */
	private function runWorkWindow(float $startedAt, float $stepWindow, int $portionLimit): bool
	{
		$connection = Application::getConnection();
		$helper = $connection->getSqlHelper();

		if ($this->passBound === null)
		{
			[$this->passLowBound, $this->passBound] = $this->selectPassBounds($connection, $helper);
			// a pass with no cursor of its own starts at the lower edge, so the lowest row of the table - including
			// one with a non-positive OWNER_ID - is inside the first range of both phases
			$this->lastOwner ??= $this->passLowBound;
			$this->lastOrphanOwner ??= $this->passLowBound;
		}

		do
		{
			if ($this->phase === self::PHASE_ORPHANS)
			{
				if (!$this->orphanRangeOpen)
				{
					$this->openOrphanRange((int)$this->lastOrphanOwner);
				}

				$queryStartedAt = $this->now();
				$orphanOwners = $this->selectOrphanOwners($connection, $helper, $portionLimit);
				$this->adjustRangeWidth($this->now() - $queryStartedAt);

				if (empty($orphanOwners))
				{
					if ($this->orphanRangeTo >= $this->passBound)
					{
						return true;
					}

					$this->lastOrphanOwner = $this->orphanRangeTo;
					$this->openOrphanRange((int)$this->lastOrphanOwner);

					continue;
				}

				$this->deleteOrphanBinds($connection, $helper, $orphanOwners);
				$this->lastOrphanOwner = (int)end($orphanOwners);

				continue;
			}

			if (!$this->rangeOpen)
			{
				// a fresh pass, or a record of the state written before the traversal by ranges
				$this->openBackfillRange((int)$this->lastOwner);
			}

			$queryStartedAt = $this->now();
			$rows = $this->selectPortion($connection, $helper, $portionLimit);
			$this->adjustRangeWidth($this->now() - $queryStartedAt);

			if (empty($rows))
			{
				// what stayed empty in the range is counted while the range is still narrow enough to count cheaply
				$this->remainder += $this->countRangeRemainder($connection, $helper);

				if ($this->rangeTo < $this->passBound)
				{
					$this->openBackfillRange($this->rangeTo);
					$this->lastOwner = $this->rangeFrom;
					$this->lastEntity = 0;
					$this->lastType = 0;

					continue;
				}

				if ($this->remainder === 0)
				{
					// the rows the filling cannot fill are exactly the orphans - their owner is missing in the
					// history - so an empty remainder leaves the phase of the orphans nothing to look for
					return true;
				}

				// the transition to the orphans is one way, and the cursor of the filling is kept as it is
				$this->phase = self::PHASE_ORPHANS;
				// the filling usually stops early on the portion limit and leaves the width at its ceiling, far above
				// the one of the orphans: the next phase starts from the default and measures its own queries
				$this->rangeWidth = $this->boundRangeWidth(self::RANGE_WIDTH_DEFAULT);

				continue;
			}

			$this->fillPortion($connection, $helper, $rows);

			$lastRow = end($rows);
			$this->lastOwner = $lastRow['OWNER_ID'];
			$this->lastEntity = $lastRow['ENTITY_ID'];
			$this->lastType = $lastRow['ENTITY_TYPE_ID'];
		}
		while ($this->now() - $startedAt < $stepWindow);

		return false;
	}

	/**
	 * The edges of the primary key the pass walks between, taken when it starts. The filter by CREATED is deliberately
	 * absent - MIN/MAX over the filtered set has no early stop and walks the whole index, while the edges of the key
	 * are answered from the metadata of the table. Bindings written above the upper edge while the pass runs need no
	 * backfill: the write path fills CREATED itself.
	 *
	 * The lower edge is answered one below the lowest key: the ranges are half-open, so the first of them has to begin
	 * below the row it is to reach. Starting there instead of at zero is what makes a row with a non-positive
	 * OWNER_ID reachable at all, and it leaves out the empty stretch below the lowest key of the table.
	 *
	 * @return array{0: int, 1: int} the lower and the upper edge
	 */
	private function selectPassBounds(Connection $connection, SqlHelper $helper): array
	{
		$owner = $helper->quote('OWNER_ID');

		$sql =
			'SELECT MIN(' . $owner . ') AS MIN_OWNER_ID, MAX(' . $owner . ') AS MAX_OWNER_ID'
			. ' FROM ' . $helper->quote('b_crm_timeline_bind')
		;

		$row = $connection->query($sql)->fetch();

		if (!$row || $row['MIN_OWNER_ID'] === null)
		{
			// an empty table has no edges, and a pass over (0, 0] is over on its first query
			return [0, 0];
		}

		return [(int)$row['MIN_OWNER_ID'] - 1, (int)$row['MAX_OWNER_ID']];
	}

	private function openBackfillRange(int $from): void
	{
		$this->rangeFrom = $from;
		$this->rangeTo = $this->rangeBoundAfter($from);
		$this->rangeOpen = true;
	}

	private function openOrphanRange(int $from): void
	{
		$this->orphanRangeTo = $this->rangeBoundAfter($from);
		$this->orphanRangeOpen = true;
	}

	private function rangeBoundAfter(int $from): int
	{
		return min((int)$this->passBound, $from + $this->rangeWidth);
	}

	/**
	 * The width is set in units of OWNER_ID while the cost of a query is set by the number of rows the range happens to
	 * hold, and that depends on the portal, so the duration of the query is the only measure of the width that survives
	 * a change of the data or of the server.
	 *
	 * A query above the target brings the width down in proportion, so it lands on the target at once; halving took
	 * four to six turns to cross the border of a dense zone, each paid for by another query as expensive as the one
	 * that started the descent. Growth stays a doubling: the empty stretches have to be crossed quickly, and the
	 * ceiling of the phase bounds the price of overshooting.
	 */
	private function adjustRangeWidth(float $queryDuration): void
	{
		if ($queryDuration > self::RANGE_QUERY_SLOW)
		{
			$scaled = (int)round($this->rangeWidth * self::RANGE_QUERY_SLOW / $queryDuration);
			$this->rangeWidth = $this->boundRangeWidth($scaled);
		}
		elseif ($queryDuration < self::RANGE_QUERY_FAST)
		{
			$this->rangeWidth = $this->boundRangeWidth($this->rangeWidth * 2);
		}
	}

	/**
	 * The ceiling belongs to the phase that is running, so a width the filling has settled on comes down to the
	 * ceiling of the orphans as soon as the pass is in that phase - including a width read back from the state.
	 */
	private function boundRangeWidth(int $rangeWidth): int
	{
		$maxRangeWidth = $this->phase === self::PHASE_ORPHANS
			? self::RANGE_WIDTH_MAX_ORPHANS
			: self::RANGE_WIDTH_MAX_BACKFILL;

		return max(self::RANGE_WIDTH_MIN, min($maxRangeWidth, $rangeWidth));
	}

	/**
	 * The journal is the only window into the backfill of a particular portal. The pace goes into the message because
	 * it is kept in options and can be changed there, so the code alone does not tell at which pace a pass has been
	 * running.
	 */
	private function reportPassStart(int $portionLimit, float $stepWindow, int $interval): void
	{
		$this->logger->info(
			'BindCreatedBackfillAgent: backfill pass started, portion {portionLimit} rows,'
			. ' step window {stepWindow}s, base interval {interval}s',
			[
				'portionLimit' => $portionLimit,
				'stepWindow' => $stepWindow,
				'interval' => $interval,
			]
		);
	}

	/**
	 * A remainder means the pass has missed rows it cannot come back to: the cursor moves on whether the update
	 * reached the row or not. One more pass from the beginning is the answer, and it is the last one - the warning of
	 * the second remainder keeps the agent from working forever.
	 *
	 * The count here covers the whole table on purpose: the accumulator the pass keeps range by range is a sum of
	 * numbers taken while the pass was running, and switching the read path of the history over has to rest on the
	 * table itself. The index of that read path is checked for the same reason: what the option is removed for has to
	 * be there before it is removed.
	 */
	private function finalize(int $interval): bool
	{
		$connection = Application::getConnection();
		$nullCount = $this->getNullCount($connection, $connection->getSqlHelper());

		if ($nullCount === 0)
		{
			if (!$connection->isIndexExists('b_crm_timeline_bind', self::READ_PATH_INDEX_COLUMNS))
			{
				$this->logger->warning(
					'BindCreatedBackfillAgent: backfill of the data completed, but the index {index}'
					. ' of b_crm_timeline_bind is missing, so the transitional option is kept as N and the'
					. ' read-path stays on legacy; the agent leaves the schedule and comes back only by'
					. ' registration: once the index is in place, call \{class}::register()',
					['index' => self::READ_PATH_INDEX, 'class' => self::class]
				);
				$this->deleteState();

				return self::AGENT_STOP;
			}

			Option::delete(self::MODULE_ID, ['name' => self::OPTION_READ_ENABLED]);
			$this->logger->info(
				'BindCreatedBackfillAgent: backfill completed successfully,'
				. ' transitional option removed, optimized read-path enabled'
			);
			$this->deleteState();

			return self::AGENT_STOP;
		}

		if ($this->passCount < self::SAFETY_PASS_LIMIT)
		{
			$this->passCount++;
			$this->lastOwner = null;
			$this->lastEntity = 0;
			$this->lastType = 0;
			$this->lastOrphanOwner = null;
			$this->phase = self::PHASE_BACKFILL;
			// the edges and the ranges belong to the pass that has ended; the width describes the server and stays
			$this->passLowBound = null;
			$this->passBound = null;
			$this->rangeFrom = 0;
			$this->rangeTo = 0;
			$this->orphanRangeTo = 0;
			$this->rangeOpen = false;
			$this->orphanRangeOpen = false;
			$this->remainder = 0;
			$this->lastStepAt = (int)$this->now();
			$this->saveState();
			$this->logger->info(
				'BindCreatedBackfillAgent: backfill finished with {nullCount} bind rows left,'
				. ' one safety pass starts',
				['nullCount' => $nullCount]
			);
			$this->setExecutionPeriod($interval);

			return self::AGENT_CONTINUE;
		}

		$this->logger->warning(
			'BindCreatedBackfillAgent: backfill completed but {nullCount} bind rows still have NULL CREATED,'
			. ' transitional option kept as N, read-path stays on legacy;'
			. ' the agent leaves the schedule and comes back only by registration:'
			. ' call \{class}::register() to start the passes over',
			['nullCount' => $nullCount, 'class' => self::class]
		);
		$this->deleteState();

		return self::AGENT_STOP;
	}

	/**
	 * Keeps the share of the working time equal to the ratio of the window of the step to the base interval: a step
	 * that took longer than its window pushes the next run further away by the same proportion. The base interval is
	 * the lower bound of the period and the ceiling is the upper one, so once the proportion asks for more than the
	 * ceiling the period stops growing with the duration and the share grows instead. The guard on the division is the
	 * last line of defence: the getter already keeps the window above zero.
	 */
	private function calculateNextPeriod(float $duration, float $stepWindow, int $interval): int
	{
		$scaled = $stepWindow > 0 ? (int)ceil($duration * $interval / $stepWindow) : $interval;

		return min($this->getMaxInterval(), max($interval, $scaled));
	}

	/**
	 * @return array<int, array{OWNER_ID: int, ENTITY_ID: int, ENTITY_TYPE_ID: int}>
	 */
	private function selectPortion(Connection $connection, SqlHelper $helper, int $portionLimit): array
	{
		$owner = $helper->quote('OWNER_ID');
		$entity = $helper->quote('ENTITY_ID');
		$type = $helper->quote('ENTITY_TYPE_ID');

		$sql = (new SqlExpression(
			'SELECT ' . $owner . ', ' . $entity . ', ' . $type
			. ' FROM ' . $helper->quote('b_crm_timeline_bind')
			. ' WHERE ' . $helper->quote('CREATED') . ' IS NULL'
			. ' AND ('
				. '(' . $owner . ' > ?i)'
				. ' OR (' . $owner . ' = ?i AND ' . $entity . ' > ?i)'
				. ' OR (' . $owner . ' = ?i AND ' . $entity . ' = ?i AND ' . $type . ' > ?i)'
			. ')'
			. ' AND ' . $owner . ' <= ?i'
			. ' ORDER BY ' . $owner . ', ' . $entity . ', ' . $type
			. ' LIMIT ?i',
			$this->lastOwner,
			$this->lastOwner,
			$this->lastEntity,
			$this->lastOwner,
			$this->lastEntity,
			$this->lastType,
			$this->rangeTo,
			$portionLimit,
		))->compile();

		$result = $connection->query($sql);
		$rows = [];
		while ($row = $result->fetch())
		{
			$rows[] = [
				'OWNER_ID' => (int)$row['OWNER_ID'],
				'ENTITY_ID' => (int)$row['ENTITY_ID'],
				'ENTITY_TYPE_ID' => (int)$row['ENTITY_TYPE_ID'],
			];
		}

		return $rows;
	}

	/**
	 * Fills CREATED for all NULL binds of the portion owners with a single batch UPDATE. The value depends on the
	 * owner only, and the CREATED IS NULL guard keeps the operation idempotent, so entity pair conditions are not
	 * needed: extra NULL binds of the last portion owner get the same correct value and are simply not selected by
	 * the next portion.
	 *
	 * @param array<int, array{OWNER_ID: int, ENTITY_ID: int, ENTITY_TYPE_ID: int}> $rows a portion that is never empty
	 */
	private function fillPortion(Connection $connection, SqlHelper $helper, array $rows): void
	{
		$portionOwners = [];
		foreach ($rows as $row)
		{
			$portionOwners[$row['OWNER_ID']] = true;
		}
		$owners = array_keys($portionOwners);

		$bindTable = $helper->quote('b_crm_timeline_bind');
		$mainTable = $helper->quote('b_crm_timeline');
		$created = $helper->quote('CREATED');

		if ($connection->getType() === 'pgsql')
		{
			$sql = new SqlExpression(
				'UPDATE ' . $bindTable . ' bind'
				. ' SET ' . $created . ' = main.' . $created
				. ' FROM ' . $mainTable . ' main'
				. ' WHERE main.' . $helper->quote('ID') . ' = bind.' . $helper->quote('OWNER_ID')
				. ' AND bind.' . $created . ' IS NULL'
				. ' AND bind.' . $helper->quote('OWNER_ID') . ' IN (?@)',
				$owners,
			);
		}
		else
		{
			$sql = new SqlExpression(
				'UPDATE ' . $bindTable . ' bind'
				. ' INNER JOIN ' . $mainTable . ' main'
					. ' ON main.' . $helper->quote('ID') . ' = bind.' . $helper->quote('OWNER_ID')
				. ' SET bind.' . $created . ' = main.' . $created
				. ' WHERE bind.' . $created . ' IS NULL'
				. ' AND bind.' . $helper->quote('OWNER_ID') . ' IN (?@)',
				$owners,
			);
		}

		$connection->queryExecute($sql->compile());
	}

	/**
	 * Selects the next portion of bind owners missing in b_crm_timeline, inside the current range. The anti-join is
	 * read-only, so it takes no write locks; the actual delete is done separately by the collected keys.
	 *
	 * @return int[]
	 */
	private function selectOrphanOwners(Connection $connection, SqlHelper $helper, int $portionLimit): array
	{
		$bindTable = $helper->quote('b_crm_timeline_bind');
		$mainTable = $helper->quote('b_crm_timeline');

		$sql = (new SqlExpression(
			'SELECT DISTINCT bind.' . $helper->quote('OWNER_ID') . ' AS ' . $helper->quote('OWNER_ID')
			. ' FROM ' . $bindTable . ' bind'
			. ' LEFT JOIN ' . $mainTable . ' main'
				. ' ON main.' . $helper->quote('ID') . ' = bind.' . $helper->quote('OWNER_ID')
			. ' WHERE main.' . $helper->quote('ID') . ' IS NULL'
			. ' AND bind.' . $helper->quote('OWNER_ID') . ' > ?i'
			. ' AND bind.' . $helper->quote('OWNER_ID') . ' <= ?i'
			. ' ORDER BY bind.' . $helper->quote('OWNER_ID')
			. ' LIMIT ?i',
			$this->lastOrphanOwner,
			$this->orphanRangeTo,
			$portionLimit,
		))->compile();

		$result = $connection->query($sql);
		$owners = [];
		while ($row = $result->fetch())
		{
			$owners[] = (int)$row['OWNER_ID'];
		}

		return $owners;
	}

	/**
	 * An owner missing in b_crm_timeline has only orphan binds, so the portion is deleted by keys without a join;
	 * the lock is short and touches the deleted rows only.
	 *
	 * @param int[] $ownerIds a portion that is never empty
	 */
	private function deleteOrphanBinds(Connection $connection, SqlHelper $helper, array $ownerIds): void
	{
		$sql = (new SqlExpression(
			'DELETE FROM ' . $helper->quote('b_crm_timeline_bind')
			. ' WHERE ' . $helper->quote('OWNER_ID') . ' IN (?@)',
			$ownerIds,
		))->compile();

		$connection->queryExecute($sql);
	}

	private function getNullCount(Connection $connection, SqlHelper $helper): int
	{
		$bindTable = $helper->quote('b_crm_timeline_bind');

		$sql =
			'SELECT COUNT(*) AS CNT FROM ' . $bindTable
			. ' WHERE ' . $helper->quote('CREATED') . ' IS NULL'
		;

		$row = $connection->query($sql)->fetch();

		return $row ? (int)$row['CNT'] : 0;
	}

	/**
	 * The rows of the range the filling has left empty. Bounded by the range the count is a range scan of the primary
	 * key, while the same count over the whole table is a full scan of the index worth seconds - which is why the pass
	 * accumulates the remainder range by range and counts the whole table once, in the finalization.
	 */
	private function countRangeRemainder(Connection $connection, SqlHelper $helper): int
	{
		$owner = $helper->quote('OWNER_ID');

		$sql = (new SqlExpression(
			'SELECT COUNT(*) AS CNT FROM ' . $helper->quote('b_crm_timeline_bind')
			. ' WHERE ' . $helper->quote('CREATED') . ' IS NULL'
			. ' AND ' . $owner . ' > ?i'
			. ' AND ' . $owner . ' <= ?i',
			$this->rangeFrom,
			$this->rangeTo,
		))->compile();

		$row = $connection->query($sql)->fetch();

		return $row ? (int)$row['CNT'] : 0;
	}

	/**
	 * An unreadable record counts as no state: the pass then starts from the beginning of the table. A record written
	 * before the traversal by ranges keeps its cursor and gets the defaults of the fields of the range - the edges of
	 * the key among them - so the pass takes the edges again and opens its first range at the cursor it resumed from.
	 *
	 * @return bool whether a state of a pass in progress was found
	 */
	private function loadState(): bool
	{
		$stored = (string)Option::get(self::STATE_MODULE_ID, self::STATE_OPTION_NAME, '');
		$decoded = $stored === '' ? null : json_decode($stored, true);
		$state = is_array($decoded) ? $decoded : [];

		$this->lastOwner = isset($state['lastOwner']) ? (int)$state['lastOwner'] : null;
		$this->lastEntity = (int)($state['lastEntity'] ?? 0);
		$this->lastType = (int)($state['lastType'] ?? 0);
		$this->lastOrphanOwner = isset($state['lastOrphanOwner']) ? (int)$state['lastOrphanOwner'] : null;
		$this->phase = ($state['phase'] ?? '') === self::PHASE_ORPHANS ? self::PHASE_ORPHANS : self::PHASE_BACKFILL;
		$this->lastStepAt = (int)($state['lastStepAt'] ?? 0);
		$this->passCount = (int)($state['passCount'] ?? 0);
		$this->passLowBound = isset($state['passLowBound']) ? (int)$state['passLowBound'] : null;
		$this->passBound = isset($state['passBound']) ? (int)$state['passBound'] : null;
		$this->rangeFrom = (int)($state['rangeFrom'] ?? 0);
		$this->rangeTo = (int)($state['rangeTo'] ?? 0);
		$this->orphanRangeTo = (int)($state['orphanRangeTo'] ?? 0);
		$this->rangeOpen = (bool)($state['rangeOpen'] ?? false);
		$this->orphanRangeOpen = (bool)($state['orphanRangeOpen'] ?? false);
		// the ceiling of the width belongs to the phase, so the phase has to be restored before it
		$this->rangeWidth = $this->boundRangeWidth((int)($state['rangeWidth'] ?? self::RANGE_WIDTH_DEFAULT));
		$this->remainder = (int)($state['remainder'] ?? 0);

		return is_array($decoded);
	}

	private function saveState(): void
	{
		Option::set(self::STATE_MODULE_ID, self::STATE_OPTION_NAME, (string)json_encode([
			'lastOwner' => $this->lastOwner,
			'lastEntity' => $this->lastEntity,
			'lastType' => $this->lastType,
			'lastOrphanOwner' => $this->lastOrphanOwner,
			'phase' => $this->phase,
			'lastStepAt' => $this->lastStepAt,
			'passCount' => $this->passCount,
			'passLowBound' => $this->passLowBound,
			'passBound' => $this->passBound,
			'rangeFrom' => $this->rangeFrom,
			'rangeTo' => $this->rangeTo,
			'orphanRangeTo' => $this->orphanRangeTo,
			'rangeOpen' => $this->rangeOpen,
			'orphanRangeOpen' => $this->orphanRangeOpen,
			'rangeWidth' => $this->rangeWidth,
			'remainder' => $this->remainder,
		]));
	}

	private function deleteState(): void
	{
		Option::delete(self::STATE_MODULE_ID, ['name' => self::STATE_OPTION_NAME]);
	}

	private function isEnabled(): bool
	{
		return Option::get(self::MODULE_ID, self::OPTION_ENABLED, 'Y') === 'Y';
	}

	/**
	 * The transitional read option is set by the released updater and removed by the finalization of a complete
	 * pass, so its absence means the work is done or was never needed on this portal. The question is about the value
	 * stored for the portal, and Option::get() would answer it with the defaults of the module as well. The site of
	 * the option is spelled out because the updater sets it for all of them, while getRealValue() defaults to the
	 * site of the current request and would not see such a value at all.
	 */
	private function isBackfillPending(): bool
	{
		return Option::getRealValue(self::MODULE_ID, self::OPTION_READ_ENABLED, '') !== null;
	}

	private function getPortionLimit(): int
	{
		$portionLimit = (int)Option::get(self::MODULE_ID, self::OPTION_STEP_LIMIT, self::DEFAULT_STEP_LIMIT);

		return max(self::STEP_LIMIT_MIN, min(self::STEP_LIMIT_MAX, $portionLimit));
	}

	private function getStepWindow(): float
	{
		$stepWindow = (float)Option::get(self::MODULE_ID, self::OPTION_TIME_LIMIT, self::DEFAULT_TIME_LIMIT);

		return max(self::TIME_LIMIT_MIN, min(self::TIME_LIMIT_MAX, $stepWindow));
	}

	/**
	 * The base interval of the pace, and the interval of the schedule record as well: registration takes it from here
	 * instead of repeating a value of its own, so a portal that has changed the option gets the same value in
	 * AGENT_INTERVAL as the runs of the agent go by.
	 */
	public static function getInterval(): int
	{
		$interval = (int)Option::get(self::MODULE_ID, self::OPTION_INTERVAL, self::DEFAULT_INTERVAL);

		return max(self::INTERVAL_MIN, min(self::INTERVAL_MAX, $interval));
	}

	/**
	 * The ceiling of the period. The base interval is its lower bound: a ceiling below it would be picked over the
	 * interval itself by the formula of the period and turn the lower bound of the pace into the upper one.
	 */
	private function getMaxInterval(): int
	{
		$maxInterval = (int)Option::get(self::MODULE_ID, self::OPTION_MAX_INTERVAL, self::DEFAULT_MAX_INTERVAL);

		return max(self::getInterval(), min(self::MAX_INTERVAL_MAX, $maxInterval));
	}

	private function now(): float
	{
		return ($this->clock)();
	}
}
