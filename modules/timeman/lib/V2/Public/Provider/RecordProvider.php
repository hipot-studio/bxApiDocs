<?php

declare(strict_types=1);

namespace Bitrix\Timeman\V2\Public\Provider;

use Bitrix\Main\ArgumentOutOfRangeException;
use Bitrix\Timeman\V2\Internal\Entity\Record\Record as RecordEntity;
use Bitrix\Timeman\V2\Internal\Entity\Schedule\Schedule;
use Bitrix\Timeman\V2\Internal\Service\UserAutomaticModeConfigurationResolver;
use Bitrix\Timeman\V2\Public\Dto\Record\AutomaticModeAvailability;
use Bitrix\Timeman\V2\Public\Dto\Record\CurrentState;
use Bitrix\Timeman\V2\Public\Dto\Record\CurrentStateCollection;
use Bitrix\Main\Provider\Params\Pager;
use Bitrix\Timeman\Model\Worktime\EventLog\WorktimeEventTable;
use Bitrix\Timeman\Repository\Worktime\WorktimeRepository;
use Bitrix\Timeman\V2\Internal\DI\Container;
use Bitrix\Timeman\V2\Internal\Integration\Intranet\UserChecker;
use Bitrix\Timeman\V2\Internal\Repository\RecordRepository;
use Bitrix\Timeman\V2\Internal\Repository\ScheduleRepository;
use Bitrix\Timeman\V2\Internal\Repository\ShiftRepository;
use Bitrix\Timeman\V2\Internal\Service\AccessService;
use Bitrix\Timeman\V2\Internal\Service\RecordService;
use Bitrix\Timeman\V2\Public\Dto\Mapper\DtoMapper;
use Bitrix\Timeman\V2\Public\Dto\Record\Record;
use Bitrix\Timeman\V2\Public\Dto\Record\RecordCollection;
use Bitrix\Timeman\V2\Public\Dto\Shift\Shift;
use Bitrix\Timeman\V2\Public\Dto\Shift\ShiftCollection;
use Bitrix\Timeman\V2\Public\Provider\Params\ListParams;
use Bitrix\Timeman\V2\Public\Provider\Params\Record\FieldsEnum;
use Bitrix\Timeman\V2\Public\Provider\Params\Record\Filter;
use Bitrix\Timeman\V2\Public\Provider\Params\Record\Sort;
use Bitrix\Timeman\V2\Public\Provider\Params\SortDirection;

final class RecordProvider
{
	public const PUSH_SCHEDULE_VERSION = 1;

	private const MAX_CURRENT_STATE_USER_IDS = 300;

	// How many records one read of the period brings back. A work day is a shift or two, so the first page
	// is the whole period of a day; a longer period is read page after page until it is exhausted.
	private const PERIOD_RECORDS_LIMIT = 100;

	// Where that reading stops regardless: a hundred pages, ten thousand shifts - more than any period is
	// worked in, and none of it a period a caller asks about. The ceiling is there so a date range gone wrong
	// cannot turn one question into an unbounded read.
	private const PERIOD_RECORDS_PAGES_LIMIT = 100;

	private readonly RecordRepository $repository;
	private readonly RecordService $worktimeRecordService;
	private readonly UserChecker $userChecker;
	private readonly ScheduleRepository $worktimeScheduleRepository;
	private readonly ShiftRepository $worktimeShiftRepository;
	private readonly WorktimeRepository $worktimeRepository;
	private readonly AccessService $accessService;
	private readonly DtoMapper $dtoMapper;

	private readonly UserAutomaticModeConfigurationResolver $userAutomaticModeConfigurationResolver;
	private readonly ?\Closure $productAvailabilityResolver;
	private readonly ?\Closure $intranetUserIdsResolver;

	public function __construct()
	{
		$container = Container::getInstance();

		$this->repository = $container->getRecordRepository();
		$this->worktimeRecordService = $container->getRecordService();
		$this->worktimeScheduleRepository = $container->getScheduleRepository();
		$this->worktimeShiftRepository = $container->getShiftRepository();
		$this->worktimeRepository = $container->getLegacyWorktimeRepository();
		$this->accessService = $container->getAccessService();

		$this->userChecker = new UserChecker();
		$this->dtoMapper = new DtoMapper();
		$this->userAutomaticModeConfigurationResolver = $container->getUserAutomaticModeConfigurationResolver();
		$this->productAvailabilityResolver = null;
		$this->intranetUserIdsResolver = null;
	}

	public function getById(
		int $recordId,
		bool $includeShift = true,
		bool $includeSchedule = true,
	): ?Record
	{
		$record = $this->repository->getById($recordId, $includeSchedule, $includeShift);

		return $record ? $this->dtoMapper->mapToDto($record, Record::class) : null;
	}

	/**
	 * Returns the latest worktime state for every requested user.
	 *
	 * @param int[] $userIds
	 */
	public function getCurrentStates(array $userIds, int $at): CurrentStateCollection
	{
		$userIds = array_values(array_unique(array_filter(
			array_map('intval', $userIds),
			static fn (int $userId): bool => $userId > 0,
		)));
		if (count($userIds) > self::MAX_CURRENT_STATE_USER_IDS)
		{
			throw new ArgumentOutOfRangeException(
				'userIds',
				null,
				self::MAX_CURRENT_STATE_USER_IDS,
			);
		}

		if ($userIds === [])
		{
			return new CurrentStateCollection();
		}

		$recordResult = $this->repository->getCurrentRecords($userIds);
		$automaticByUser = [];
		try
		{
			if (!$this->isProductAvailable())
			{
				foreach ($userIds as $userId)
				{
					$automaticByUser[$userId] = $this->getNotConfiguredAutomaticState();
				}
			}
			else
			{
				$intranetUserIds = $this->getIntranetUserIds($userIds);
				$intranetUserIdMap = array_fill_keys($intranetUserIds, true);
				foreach ($userIds as $userId)
				{
					if (!isset($intranetUserIdMap[$userId]))
					{
						$automaticByUser[$userId] = $this->getNotConfiguredAutomaticState();
					}
				}

				if ($intranetUserIds !== [])
				{
					$automaticByUser += $this->userAutomaticModeConfigurationResolver->resolveForUserIds($intranetUserIds);
				}
			}
		}
		catch (\Throwable)
		{
		}

		$collection = new CurrentStateCollection();
		foreach ($userIds as $userId)
		{
			if (isset($recordResult['failedUserIds'][$userId]))
			{
				$collection->add($this->buildUnknownCurrentState($userId, $automaticByUser[$userId] ?? null));

				continue;
			}

			try
			{
				$collection->add($this->buildCurrentState(
					$userId,
					$recordResult['records'][$userId] ?? null,
					$automaticByUser[$userId] ?? null,
				));
			}
			catch (\Throwable)
			{
				$collection->add($this->buildUnknownCurrentState($userId, $automaticByUser[$userId] ?? null));
			}
		}

		return $collection;
	}

	public function getCurrentRecord(
		int $userId,
		bool $includeShift = true,
		bool $includeSchedule = true,
	): ?Record
	{
		$record = $this->repository->getCurrentRecord($userId, $includeSchedule, $includeShift);

		return $record ? $this->dtoMapper->mapToDto($record, Record::class) : null;
	}

	public function getRecords(
		ListParams $params,
		bool $includeSchedule = false,
		bool $includeShift = false,
	): RecordCollection
	{
		if (!($params->filter instanceof Filter))
		{
			return new RecordCollection();
		}

		$records = $this->repository->getUsersRecords(
			userIds: $params->filter->getUserIds(),
			dateFrom: $params->filter->getDateFrom(),
			dateTo: $params->filter->getDateTo(),
			select: $params->getSelect(),
			sort: $params->getSort(),
			includeSchedule: $includeSchedule,
			includeShift: $includeShift,
			offset: $params->getOffset(),
			limit: $params->getLimit(),
		);

		return $this->dtoMapper->mapToDtoCollection(
			$records,
			Record::class,
			RecordCollection::class,
		);
	}

	public function getRecordIdsForPeriod(int $userId, int $dateFrom, int $dateTo): array
	{
		return $this->repository->getRecordIdsForPeriod($userId, $dateFrom, $dateTo);
	}

	/**
	 * Intervals the work day spent paused within the period, both ends included. A record keeps no
	 * interval of its own - only the current status and the total break length - so the intervals are
	 * rebuilt from the paired entries of its event log.
	 *
	 * Every record is paired on its own. A day of two shifts writes two runs of entries, and read as one
	 * run the break the first shift was stopped in would go on covering the second - or be closed by a
	 * CONTINUE that belongs to a break of its own. So a break left open by the record it stands on ends
	 * where that record does, and a record that has not stopped yet ends nowhere: such a break comes back
	 * with to = null, the end of the interval is not known.
	 *
	 * The period, not the record, decides what is answered: a record crossing midnight ends outside the
	 * day it started in, so entries falling out of the period are cut off - and so is the end a break left
	 * open is closed with. A record stopped past the period ends such a break at the last moment the period
	 * holds: the CONTINUE that really closed it was cut off along with everything else lying outside, and an
	 * answer about a period carries no moment beyond it. The left edge mirrors that: a record already paused
	 * on its way into the period brings the break in with it, starting at the first moment the period holds,
	 * and a break over before the period never began as far as the answer goes.
	 *
	 * The period is asked about as a whole, however long it is: the records it is made of are read page after
	 * page until it is exhausted, so a month of shifts answers for the day it began with as much as for the
	 * day it ended with. The only period that comes back short is one of more than PERIOD_RECORDS_PAGES_LIMIT
	 * pages of shifts - ten thousand of them, worked by nobody - which is answered for the shifts it began
	 * with, as many as those pages hold, and read no further.
	 *
	 * Answers nothing to a reader who may not read the work time of that employee.
	 *
	 * @return array<int, array{from: int, to: ?int}>
	 */
	public function getPauseIntervals(int $userId, int $dateFrom, int $dateTo): array
	{
		if (!$this->canReadWorktime($userId))
		{
			return [];
		}

		return $this->pairBreaksOfRecords(
			$this->getPeriodRecords($userId, $dateFrom, $dateTo),
			$userId,
			$dateFrom,
			$dateTo,
		);
	}

	/**
	 * The same intervals for a caller that has already read the records of the period. A consumer reading
	 * that window for something else of its own puts the question to work time tracking once instead of
	 * twice. This door answers for exactly the records handed in: unlike getPauseIntervals(), it does not
	 * go after the record crossing the left edge of the period - whether that record belongs to the
	 * question is the caller's to decide (the day of the check-in timeline, for one, hands it in only for
	 * the day being lived through).
	 *
	 * The records are taken as handed in: what belongs to the period was decided by the read that produced
	 * them, and only their event log is cut back to the period here. What they belong to is not taken on
	 * trust, though - a record of another employee among them is dropped rather than paired, so the right
	 * asked below and the answer given back speak of one and the same person.
	 *
	 * Answers nothing to a reader who may not read the work time of that employee.
	 *
	 * @param iterable<Record> $records
	 * @return array<int, array{from: int, to: ?int}>
	 */
	public function getPauseIntervalsOfRecords(int $userId, iterable $records, int $dateFrom, int $dateTo): array
	{
		if (!$this->canReadWorktime($userId))
		{
			return [];
		}

		return $this->pairBreaksOfRecords($records, $userId, $dateFrom, $dateTo);
	}

	/**
	 * @param iterable<Record> $periodRecords
	 * @return array<int, array{from: int, to: ?int}>
	 */
	private function pairBreaksOfRecords(iterable $periodRecords, int $userId, int $dateFrom, int $dateTo): array
	{
		$records = [];
		foreach ($periodRecords as $record)
		{
			// The right was asked of one employee, so only that employee is answered for: records read here
			// carry their owner anyway, and records handed in from outside cannot smuggle another one past it.
			if ($record->userId === $userId)
			{
				$records[$record->id] = $record;
			}
		}

		// The log of every record of the period is read at once and only then split back by record: pairing
		// belongs to one record alone, but reading belongs to the period.
		$eventsByRecord = $this->collectPauseEvents(array_keys($records), $dateTo);

		$intervals = [];
		foreach ($records as $recordId => $record)
		{
			$intervals = array_merge(
				$intervals,
				$this->foldPauseIntervals($eventsByRecord[$recordId] ?? [], $record->endTime, $dateFrom, $dateTo),
			);
		}

		// The records are answered in an order of their own, so the day is put back in the order it went
		// through the breaks only here.
		usort($intervals, static fn(array $left, array $right): int => $left['from'] <=> $right['from']);

		return $intervals;
	}

	public function canUseTimeMan(?int $userId = null): bool
	{
		if ($userId && $this->userChecker->isExtranet($userId))
		{
			return false;
		}

		return (
			\CBXFeatures::isFeatureEnabled('timeman')
			&& \CTimeMan::canUse()
		);
	}

	/**
	 * Whether the reader may see the work time of that employee - the same right the provider applies to its
	 * own answers, said out loud so a consumer holding data of that work day can hold to it without
	 * restating the rule for itself. The right itself lives in the access service of the v2 layer; the
	 * provider only asks it, here and before its own answers.
	 */
	public function canReadWorktime(int $userId): bool
	{
		return $this->accessService->canReadWorktime($userId);
	}

	/**
	 * canReadWorktime() asked about a whole team at once: the employees among those given whose work time the
	 * reader may see. The users he may read are resolved once per call, so N employees against M readable
	 * users cost O(N + M) instead of O(N * M) of a check per employee.
	 *
	 * Ids are taken as getCurrentStates() takes them: cast to int, the non-positive ones dropped, a repeated one
	 * answered once. The readable ones come back as a list in the order of their first occurrence.
	 *
	 * @param array $userIds
	 * @return int[]
	 */
	public function filterReadableWorktimeUserIds(array $userIds): array
	{
		$userIds = array_values(array_unique(array_filter(
			array_map('intval', $userIds),
			static fn (int $userId): bool => $userId > 0,
		)));

		return $this->accessService->filterReadableWorktimeUserIds($userIds);
	}

	public function getAutomaticModeAvailability(int $userId): AutomaticModeAvailability
	{
		return $this->resolveAutomaticModeAvailability($userId, false);
	}

	public function getFreshAutomaticModeAvailability(int $userId): AutomaticModeAvailability
	{
		return $this->resolveAutomaticModeAvailability($userId, true);
	}

	private function resolveAutomaticModeAvailability(int $userId, bool $fresh): AutomaticModeAvailability
	{
		if (
			$userId <= 0
			|| !$this->isProductAvailable()
			|| $this->userChecker->isExtranet($userId)
		)
		{
			return AutomaticModeAvailability::notConfigured();
		}

		$configByUser = $fresh
			? $this->userAutomaticModeConfigurationResolver->resolveFreshForUserIds([$userId])
			: $this->userAutomaticModeConfigurationResolver->resolveForUserIds([$userId]);
		$config = $configByUser[$userId] ?? null;
		if (!is_array($config) || !$config['trusted'])
		{
			return AutomaticModeAvailability::unavailable();
		}

		if ($config['hasSchedule'])
		{
			return new AutomaticModeAvailability(
				trusted: true,
				available: true,
				mode: AutomaticModeAvailability::MODE_SCHEDULE,
				unavailableReason: null,
			);
		}

		if ($config['hasFlexibleSchedule'] || $config['timeTrackingEnabled'])
		{
			return new AutomaticModeAvailability(
				trusted: true,
				available: true,
				mode: AutomaticModeAvailability::MODE_TIME_TRACKING,
				unavailableReason: null,
			);
		}

		return AutomaticModeAvailability::notConfigured();
	}

	private function isProductAvailable(): bool
	{
		return $this->productAvailabilityResolver !== null
			? (bool)($this->productAvailabilityResolver)()
			: \CBXFeatures::isFeatureEnabled('timeman');
	}

	/** @param int[] $userIds */
	private function getIntranetUserIds(array $userIds): array
	{
		return $this->intranetUserIdsResolver !== null
			? ($this->intranetUserIdsResolver)($userIds)
			: $this->userChecker->excludeExtranetUserIds($userIds);
	}

	private function getNotConfiguredAutomaticState(): array
	{
		return [
			'trusted' => true,
			'hasSchedule' => false,
			'hasFlexibleSchedule' => false,
			'timeTrackingEnabled' => false,
		];
	}

	private function buildCurrentState(int $userId, ?RecordEntity $record, mixed $automatic): CurrentState
	{
		$automaticState = $this->mapAutomaticState($automatic);
		if ($record === null)
		{
			return new CurrentState(
				userId: $userId,
				trusted: true,
				recordId: null,
				status: CurrentState::STATUS_NONE,
				startTimestamp: null,
				stopTimestamp: null,
				scheduleType: CurrentState::SCHEDULE_TYPE_UNKNOWN,
				automaticConfigurationTrusted: $automaticState['automaticConfigurationTrusted'],
				automaticConfigured: $automaticState['automaticConfigured'],
				automaticMode: $automaticState['automaticMode'],
			);
		}

		return new CurrentState(
			userId: $userId,
			trusted: true,
			recordId: $record->id,
			status: $record->state->status->value,
			startTimestamp: $record->startTime,
			stopTimestamp: $record->endTime,
			scheduleType: $this->mapScheduleType($record),
			automaticConfigurationTrusted: $automaticState['automaticConfigurationTrusted'],
			automaticConfigured: $automaticState['automaticConfigured'],
			automaticMode: $automaticState['automaticMode'],
		);
	}

	private function buildUnknownCurrentState(int $userId, mixed $automatic): CurrentState
	{
		$automaticState = $this->mapAutomaticState($automatic);

		return new CurrentState(
			userId: $userId,
			trusted: false,
			recordId: null,
			status: CurrentState::STATUS_UNKNOWN,
			startTimestamp: null,
			stopTimestamp: null,
			scheduleType: CurrentState::SCHEDULE_TYPE_UNKNOWN,
			automaticConfigurationTrusted: $automaticState['automaticConfigurationTrusted'],
			automaticConfigured: $automaticState['automaticConfigured'],
			automaticMode: $automaticState['automaticMode'],
		);
	}

	private function mapAutomaticState(mixed $automatic): array
	{
		if (!is_array($automatic) || !($automatic['trusted'] ?? false))
		{
			return [
				'automaticConfigurationTrusted' => false,
				'automaticConfigured' => false,
				'automaticMode' => AutomaticModeAvailability::MODE_UNKNOWN,
			];
		}
		if ($automatic['hasSchedule'] ?? false)
		{
			return [
				'automaticConfigurationTrusted' => true,
				'automaticConfigured' => true,
				'automaticMode' => AutomaticModeAvailability::MODE_SCHEDULE,
			];
		}
		if (($automatic['hasFlexibleSchedule'] ?? false) || ($automatic['timeTrackingEnabled'] ?? false))
		{
			return [
				'automaticConfigurationTrusted' => true,
				'automaticConfigured' => true,
				'automaticMode' => AutomaticModeAvailability::MODE_TIME_TRACKING,
			];
		}

		return [
			'automaticConfigurationTrusted' => true,
			'automaticConfigured' => false,
			'automaticMode' => AutomaticModeAvailability::MODE_NONE,
		];
	}

	private function mapScheduleType(RecordEntity $record): string
	{
		return match ($record->schedule?->type)
		{
			Schedule::TYPE_FIXED => CurrentState::SCHEDULE_TYPE_FIXED,
			Schedule::TYPE_SHIFT => CurrentState::SCHEDULE_TYPE_SHIFT,
			Schedule::TYPE_FLEXTIME => CurrentState::SCHEDULE_TYPE_FLEX,
			default => CurrentState::SCHEDULE_TYPE_UNKNOWN,
		};
	}

	public function canManageWorktimeOnMobile(int $userId): bool
	{
		return $this->worktimeRecordService->canManageWorkTimeOnMobile($userId);
	}

	public function getNextShift(int $userId): ?Shift
	{
		$schedules = $this->worktimeScheduleRepository->findByUserId($userId);
		if (
			$schedules->count() === 0
			|| $this->worktimeScheduleRepository->hasFlextime($schedules)
			|| !$this->worktimeShiftRepository->hasActiveShifts($schedules)
		)
		{
			return null;
		}

		$shift = $this->worktimeShiftRepository->findNextByUserId($userId, $schedules);

		return $shift ? $this->dtoMapper->mapToDto($shift, Shift::class) : null;
	}

	public function getNearestShifts(int $userId): ShiftCollection
	{
		$shifts = $this->worktimeShiftRepository->findNearestShifts($userId);

		return $this->dtoMapper->mapToDtoCollection(
			$shifts,
			Shift::class,
			ShiftCollection::class,
		);
	}

	/**
	 * The records the period is made of: the ones that began in it, and the one crossing its left edge -
	 * begun before the period and still on at its first moment. Shifts do not overlap, so at most one
	 * crosses the edge, and it is the latest of the ones begun before it; a shift over before the period
	 * stays out. Without it the promise of the left edge - a break entered before the period comes back as
	 * its intersection - would speak of records this reading never picked up.
	 *
	 * All of them, not one page of them: the reading goes on page after page and stops at the first page that
	 * comes back short of the limit, which is the page the period ends on. A single read would answer a long
	 * period with PERIOD_RECORDS_LIMIT of its shifts and say nothing about the ones it left out.
	 *
	 * The pages are cut apart by an order two records cannot share - the moment a record began, and its id
	 * under that - so nothing falls between two pages of shifts that began at the very same second. That order
	 * runs with the period, from its earliest shift onwards: a shift begun while the period is being read
	 * falls past the pages already taken instead of pushing them along and squeezing a record out between two
	 * of them.
	 *
	 * @return iterable<Record>
	 */
	private function getPeriodRecords(int $userId, int $dateFrom, int $dateTo): iterable
	{
		$records = [];

		$edgeRecords = $this->getRecords(
			new ListParams(
				pager: new Pager(limit: 1, offset: 0),
				filter: new Filter(
					userId: $userId,
					dateTo: $dateFrom - 1,
				),
				sort: new Sort([
					FieldsEnum::RecordedStartTimestamp->value => SortDirection::Desc->value,
					FieldsEnum::Id->value => SortDirection::Desc->value,
				]),
			)
		);
		foreach ($edgeRecords as $record)
		{
			if ($record->endTime === null || $record->endTime >= $dateFrom)
			{
				$records[] = $record;
			}
		}

		for ($page = 0; $page < self::PERIOD_RECORDS_PAGES_LIMIT; $page++)
		{
			$pageRecords = $this->getRecords(
				new ListParams(
					pager: new Pager(
						limit: self::PERIOD_RECORDS_LIMIT,
						offset: $page * self::PERIOD_RECORDS_LIMIT,
					),
					filter: new Filter(
						userId: $userId,
						dateFrom: $dateFrom,
						dateTo: $dateTo,
					),
					sort: new Sort([
						FieldsEnum::RecordedStartTimestamp->value => SortDirection::Asc->value,
						FieldsEnum::Id->value => SortDirection::Asc->value,
					]),
				)
			);

			foreach ($pageRecords as $record)
			{
				$records[] = $record;
			}

			if ($pageRecords->count() < self::PERIOD_RECORDS_LIMIT)
			{
				break;
			}
		}

		return $records;
	}

	/**
	 * Pause and continue entries of the records handed in, kept apart by the record they stand on and put in
	 * the order that record went through them. The log of all of them is read at once and by the key the
	 * entries hang on, so the read costs the same whether the day is one shift or the ceiling of them; the
	 * entries are ordered against each other here rather than by the read.
	 *
	 * A record whose log holds nothing of the period is absent from the answer, which reads as the empty run
	 * of entries it is.
	 *
	 * Entries BEFORE the period are kept: a record handed in from outside may have entered the period
	 * already paused, and the break it stands in is only there to pair if the PAUSE that opened it is. The
	 * pairing reads them all and the interval is then cut back to the period (foldPauseIntervals) - which
	 * is how the right edge has always been answered, and the left edge is its mirror. Entries past the
	 * period stay cut off: what the day did after the period closes no break of it.
	 *
	 * The event names of the log are spoken here once, as the filter the repository reads by and as the
	 * pause/continue distinction the entries keep; past this point the pairing works on that distinction
	 * alone.
	 *
	 * @param int[] $recordIds
	 * @return array<int, array<int, array{isPause: bool, timestamp: int, id: int}>> keyed by record id
	 */
	private function collectPauseEvents(array $recordIds, int $dateTo): array
	{
		$pauseEvents = $this->worktimeRepository->findAllWorktimeEventsByRecordIds($recordIds, [
			WorktimeEventTable::EVENT_TYPE_PAUSE,
			WorktimeEventTable::EVENT_TYPE_CONTINUE,
		]);

		$events = [];
		foreach ($pauseEvents as $event)
		{
			$timestamp = $event->getActualTimestamp();

			if (
				$timestamp === null
				|| $timestamp > $dateTo
			)
			{
				continue;
			}

			$events[(int)$event->getWorktimeRecordId()][] = [
				'isPause' => $event->getEventType() === WorktimeEventTable::EVENT_TYPE_PAUSE,
				'timestamp' => (int)$timestamp,
				'id' => (int)$event->getId(),
			];
		}

		return array_map(
			static function (array $recordEvents): array {
				usort($recordEvents, static function (array $left, array $right): int {
					return [$left['timestamp'], $left['id']] <=> [$right['timestamp'], $right['id']];
				});

				return $recordEvents;
			},
			$events,
		);
	}

	/**
	 * The breaks of one record, paired within it alone.
	 *
	 * The entries may reach back before the period - that is how a record already paused on its way in is
	 * read - so what the pairing produces is cut back to the period at the end: a break over before the
	 * period never began as far as the answer goes, and one entered inside starts where the period does.
	 *
	 * @param array<int, array{isPause: bool, timestamp: int, id: int}> $events
	 * @param ?int $recordEnd the moment the record was stopped at, null while it is still running
	 * @param int $dateFrom the first moment the period holds - no interval answered begins before it
	 * @param int $dateTo the last moment the period holds - no interval answered reaches past it
	 * @return array<int, array{from: int, to: ?int}>
	 */
	private function foldPauseIntervals(array $events, ?int $recordEnd, int $dateFrom, int $dateTo): array
	{
		$intervals = [];
		$openedAt = null;

		foreach ($events as $event)
		{
			if ($event['isPause'])
			{
				// A repeated pause keeps the earliest one: the day was paused then and has stayed paused since.
				$openedAt ??= $event['timestamp'];

				continue;
			}

			if ($openedAt !== null)
			{
				$intervals[] = ['from' => $openedAt, 'to' => $event['timestamp']];
				$openedAt = null;
			}
		}

		if ($openedAt !== null)
		{
			// A record stopped while it was paused ends the break with itself: whatever the day did after
			// that belongs to another record and to no break of this one. Stopped past the period, it ends
			// the break at the edge of the period instead - the shift went on and was continued somewhere out
			// there, and the answer keeps to what it was asked about. A record still running ends nowhere,
			// and neither does the break it is standing in.
			$intervals[] = [
				'from' => $openedAt,
				'to' => $recordEnd === null ? null : max($openedAt, min($recordEnd, $dateTo)),
			];
		}

		$cut = [];
		foreach ($intervals as $interval)
		{
			if ($interval['to'] !== null && $interval['to'] <= $dateFrom)
			{
				continue;
			}

			$cut[] = ['from' => max($interval['from'], $dateFrom), 'to' => $interval['to']];
		}

		return $cut;
	}
}
