<?php

declare(strict_types=1);

namespace Bitrix\Landing\Integration\AiAssistant\Service;

use Bitrix\Landing\Copilot\Generation;
use Bitrix\Landing\Copilot\Generation\Scenario\CreateMarkupSite;
use Bitrix\Landing\Copilot\Model\GenerationsTable;
use Bitrix\Landing\Integration\AiAssistant\Model\AiSiteBindingTable;
use Bitrix\Landing\Markup\MarkupCreationOutcome;
use Bitrix\Landing\Markup\MarkupLifecycleLog;
use Bitrix\Main\Config\Option;
use Bitrix\Main\ORM\Fields\Relations\Reference;
use Bitrix\Main\ORM\Query\Join;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Main\Type\DateTime;

/**
 * The safety net of the markup chat reservations (ADR § 3.3): a chat reserved by a markup creation that
 * nobody has reacted on - the terminal hook lost its removal, a cancel found the generation locked, the
 * generation vanished - stays locked until this sweep frees it.
 *
 * A reservation older than the threshold is released when its markup creation is terminal - through the
 * one reaction of the scenario, which also removes the partial site of a known siteId - or when its
 * generation no longer exists - then only the reservation goes, there is no site to know of. A live
 * generation and a reservation of another scenario are never touched. The generation is read again
 * under its lock before the reaction: one that its retry agent has finished in the meantime is skipped.
 */
class StaleReservationSweeper
{
	public const DEFAULT_AGE_SECONDS = 7200;

	private const BATCH_SIZE = 100;
	private const RUN_LIMIT = 50;
	private const CURSOR_OPTION = 'markup_stale_reservation_sweep_cursor';
	private const GENERATION_ALIAS = 'SWEEP_GENERATION';
	private const EXISTING_GENERATION_FIELD = 'SWEEP_GENERATION_ID';

	private AiSiteBindingService $bindingService;
	private MarkupCreationOutcome $outcome;
	private MarkupLifecycleLog $log;

	public function __construct(
		?AiSiteBindingService $bindingService = null,
		?MarkupCreationOutcome $outcome = null,
		?MarkupLifecycleLog $log = null,
	)
	{
		$this->bindingService = $bindingService ?? new AiSiteBindingService();
		$this->log = $log ?? new MarkupLifecycleLog();
		$this->outcome = $outcome ?? new MarkupCreationOutcome(
			bindingService: $this->bindingService,
			log: $this->log,
		);
	}

	/**
	 * A run checks a limited number of reservations. It resumes after the reservation the previous
	 * capped run stopped at and wraps to the head once, so a reservation that stays (a live generation,
	 * a failed removal) is read again once per round instead of blocking the ones behind it.
	 * @return int The number of reservations released by this sweep.
	 */
	public function sweep(int $olderThanSeconds = self::DEFAULT_AGE_SECONDS): int
	{
		$threshold = (new DateTime())->add('-' . max(0, $olderThanSeconds) . ' seconds');
		$limit = max(1, $this->getRunLimit());
		$startId = max(0, $this->loadCursor());
		$released = 0;
		$checked = 0;
		$lastId = $startId;
		$upToId = null;

		while (true)
		{
			$batchSize = min(self::BATCH_SIZE, $limit - $checked);
			$rows = $this->findStaleReservations($threshold, $lastId, $upToId, $batchSize);
			foreach ($rows as $row)
			{
				$lastId = (int)$row['ID'];
				$checked++;
				if ($this->sweepReservation($row))
				{
					$released++;
				}
			}

			if ($checked >= $limit)
			{
				$this->saveCursor($lastId);

				return $released;
			}

			if (count($rows) < $batchSize)
			{
				if ($upToId !== null || $startId === 0)
				{
					break;
				}

				// the tail is done: the head up to the resume point is left
				$upToId = $startId;
				$lastId = 0;
			}
		}

		$this->saveCursor(0);

		return $released;
	}

	/**
	 * The query of stale reservations: a draft without a site, reserved by a generation before the
	 * threshold, whose generation is a markup creation or is gone.
	 */
	protected function createStaleQuery(DateTime $threshold): Query
	{
		return AiSiteBindingTable::query()
			->registerRuntimeField(new Reference(
				self::GENERATION_ALIAS,
				GenerationsTable::class,
				Join::on('this.GENERATION_ID', 'ref.ID'),
				['join_type' => Join::TYPE_LEFT],
			))
			->setSelect([
				'ID',
				'USER_ID',
				'GENERATION_ID',
				self::EXISTING_GENERATION_FIELD => self::GENERATION_ALIAS . '.ID',
			])
			->whereNull('SITE_ID')
			->whereNotNull('GENERATION_ID')
			->where('DATE_MODIFY', '<', $threshold)
			->where(
				Query::filter()
					->logic('or')
					->whereNull(self::GENERATION_ALIAS . '.ID')
					->where(self::GENERATION_ALIAS . '.SCENARIO', '=', CreateMarkupSite::class)
			)
		;
	}

	protected function getRunLimit(): int
	{
		return self::RUN_LIMIT;
	}

	/**
	 * @return int The binding id the next run resumes after, 0 for the head.
	 */
	protected function loadCursor(): int
	{
		return (int)Option::get('landing', self::CURSOR_OPTION, 0);
	}

	protected function saveCursor(int $bindingId): void
	{
		if ($bindingId > 0)
		{
			Option::set('landing', self::CURSOR_OPTION, $bindingId);
		}
		else
		{
			Option::delete('landing', ['name' => self::CURSOR_OPTION]);
		}
	}

	protected function loadGeneration(int $generationId): ?Generation
	{
		$generation = new Generation();

		return $generation->initById($generationId) ? $generation : null;
	}

	/**
	 * False, and nothing is run, while a run of the generation holds its lock.
	 */
	protected function runUnderGenerationLock(Generation $generation, callable $operation): bool
	{
		return $generation->runExclusively($operation);
	}

	/**
	 * @return list<array{ID: int, USER_ID: int, GENERATION_ID: int, GENERATION_EXISTS: bool}>
	 */
	private function findStaleReservations(DateTime $threshold, int $afterId, ?int $upToId, int $limit): array
	{
		$query = $this->createStaleQuery($threshold)
			->where('ID', '>', $afterId)
			->setOrder(['ID' => 'ASC'])
			->setLimit($limit)
		;
		if ($upToId !== null)
		{
			$query->where('ID', '<=', $upToId);
		}
		$rows = $query->fetchAll();

		return array_map(static fn(array $row): array => [
			'ID' => (int)$row['ID'],
			'USER_ID' => (int)$row['USER_ID'],
			'GENERATION_ID' => (int)$row['GENERATION_ID'],
			'GENERATION_EXISTS' => (int)($row[self::EXISTING_GENERATION_FIELD] ?? 0) > 0,
		], $rows);
	}

	private function sweepReservation(array $row): bool
	{
		try
		{
			return $row['GENERATION_EXISTS']
				? $this->sweepTerminalGeneration($row)
				: $this->releaseOrphan($row)
			;
		}
		catch (\Throwable $exception)
		{
			$this->write($row, 'failed: ' . get_class($exception) . ': ' . $exception->getMessage());

			return false;
		}
	}

	/**
	 * Nothing is known of a generation that is gone, its site included: only the reservation is released.
	 */
	private function releaseOrphan(array $row): bool
	{
		if (!$this->bindingService->releaseDraftForFailedGeneration($row['GENERATION_ID'], $row['USER_ID']))
		{
			return false;
		}

		$this->write($row, 'orphan_released');

		return true;
	}

	private function sweepTerminalGeneration(array $row): bool
	{
		$generation = $this->loadGeneration($row['GENERATION_ID']);
		if ($generation === null || !$this->isTerminalCreation($generation))
		{
			return false;
		}

		$hasReacted = false;
		$this->runUnderGenerationLock($generation, function () use ($row, &$hasReacted): void {
			// the retry agent may have finished or restarted the generation since it was read
			$current = $this->loadGeneration($row['GENERATION_ID']);
			if ($current !== null && $this->isTerminalCreation($current))
			{
				$this->outcome->reactOnTerminal($current);
				$hasReacted = true;
			}
		});
		if (!$hasReacted)
		{
			return false;
		}

		$binding = $this->bindingService->findByGenerationId($row['GENERATION_ID']);
		$isReleased = $binding === null || (int)$binding['ID'] !== $row['ID'];
		// a reservation that is kept waits for the next sweep: the removal of its site has failed
		$this->write($row, $isReleased ? 'released' : 'kept');

		return $isReleased;
	}

	private function isTerminalCreation(Generation $generation): bool
	{
		$scenario = $generation->getScenario();

		return $scenario !== null
			&& get_class($scenario) === CreateMarkupSite::class
			&& !$generation->isFinished()
			&& $generation->isErrorPersisted()
		;
	}

	private function write(array $row, string $reason): void
	{
		$this->log->write(MarkupLifecycleLog::EVENT_SWEEP, [
			'jobId' => $row['GENERATION_ID'],
			'bindingId' => $row['ID'],
			'userId' => $row['USER_ID'],
			'reason' => mb_strcut($reason, 0, 180),
		]);
	}
}
