<?php

declare(strict_types=1);

namespace Bitrix\Timeman\V2\Public\Provider;

use Bitrix\Timeman\V2\Internal\DI\Container;
use Bitrix\Timeman\V2\Internal\Integration\Intranet\UserChecker;
use Bitrix\Timeman\V2\Internal\Repository\RecordRepository;
use Bitrix\Timeman\V2\Internal\Repository\ScheduleRepository;
use Bitrix\Timeman\V2\Internal\Repository\ShiftRepository;
use Bitrix\Timeman\V2\Internal\Service\RecordService;
use Bitrix\Timeman\V2\Public\Dto\Mapper\DtoMapper;
use Bitrix\Timeman\V2\Public\Dto\Record\Record;
use Bitrix\Timeman\V2\Public\Dto\Record\RecordCollection;
use Bitrix\Timeman\V2\Public\Dto\Shift\Shift;
use Bitrix\Timeman\V2\Public\Dto\Shift\ShiftCollection;
use Bitrix\Timeman\V2\Public\Provider\Params\Record\Filter;
use Bitrix\Timeman\V2\Public\Provider\Params\ListParams;

final class RecordProvider
{
	private readonly RecordRepository $repository;
	private readonly RecordService $worktimeRecordService;
	private readonly UserChecker $userChecker;
	private readonly ScheduleRepository $worktimeScheduleRepository;
	private readonly ShiftRepository $worktimeShiftRepository;
	private readonly DtoMapper $dtoMapper;

	public function __construct()
	{
		$container = Container::getInstance();

		$this->repository = $container->getRecordRepository();
		$this->worktimeRecordService = $container->getRecordService();
		$this->worktimeScheduleRepository = $container->getScheduleRepository();
		$this->worktimeShiftRepository = $container->getShiftRepository();

		$this->userChecker = new UserChecker();
		$this->dtoMapper = new DtoMapper();
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
}
