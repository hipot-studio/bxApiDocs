<?php

declare(strict_types=1);

namespace Bitrix\Timeman\V2\Internal\Service;

use Bitrix\Main\Result;
use Bitrix\Timeman\Form\Worktime\WorktimeRecordForm as LegacyWorktimeRecordForm;
use Bitrix\Timeman\Helper\TimeHelper;
use Bitrix\Timeman\Service\DependencyManager;
use Bitrix\Timeman\Service\Worktime\Result\WorktimeServiceResult;
use Bitrix\Timeman\Service\Worktime\WorktimeService as LegacyWorktimeService;
use Bitrix\Timeman\Model\Schedule\Schedule;
use Bitrix\Timeman\Model\Schedule\ScheduleTable;
use Bitrix\Timeman\V2\Internal\Entity\Record\RecordForm;
use Bitrix\Timeman\V2\Internal\Repository\RecordRepository;
use Bitrix\Timeman\Repository\Schedule\ScheduleRepository;

class RecordService
{
	private readonly LegacyWorktimeService $legacyService;
	private readonly ScheduleRepository $legacyScheduleRepository;

	public function __construct(
		private readonly RecordRepository $recordRepository,
		?LegacyWorktimeService $legacyService = null,
		?ScheduleRepository $legacyScheduleRepository = null,
	)
	{
		$this->legacyService = $legacyService
			?? DependencyManager::getInstance()->getWorktimeService();
		$this->legacyScheduleRepository = $legacyScheduleRepository
			?? DependencyManager::getInstance()->getScheduleRepository();
	}

	public function startWorktime(LegacyWorktimeRecordForm $recordForm): Result
	{
		$result = $this->legacyService->startWorktime($recordForm);

		if ($result->isSuccess())
		{
			$this->handleSuccessfulExecution(
				$recordForm->userId,
				'OnAfterTMDayStart',
				$result,
			);
		}

		return $result;
	}

	public function stopWorktime(LegacyWorktimeRecordForm $recordForm): Result
	{
		$result = $this->legacyService->stopWorktime($recordForm);

		if ($result->isSuccess())
		{
			$this->handleSuccessfulExecution(
				$recordForm->userId,
				'OnAfterTMDayEnd',
				$result,
			);
		}

		return $result;
	}

	public function pauseWorktime(LegacyWorktimeRecordForm $recordForm): Result
	{
		$result = $this->legacyService->pauseWork($recordForm);

		if ($result->isSuccess())
		{
			$this->handleSuccessfulExecution(
				$recordForm->userId,
				'OnAfterTMDayPause',
				$result,
			);
		}

		return $result;
	}

	public function continueWorktime(LegacyWorktimeRecordForm $recordForm): Result
	{
		$result = $this->legacyService->continueWork($recordForm);

		if ($result->isSuccess())
		{
			$this->handleSuccessfulExecution(
				$recordForm->userId,
				'OnAfterTMDayContinue',
				$result,
			);
		}

		return $result;
	}

	public function createLegacyRecordForm(RecordForm $recordForm): LegacyWorktimeRecordForm
	{
		$form = LegacyWorktimeRecordForm::createWithEventForm();

		$userId = $recordForm->userId;

		$form->userId = $userId;
		$form->id = $recordForm->recordId;
		$form->scheduleId = $recordForm->scheduleId;
		$form->shiftId = $recordForm->shiftId;
		$form->tasks = $recordForm->tasks;
		$form->ipOpen = $recordForm->ipOpen;
		$form->ipClose = $recordForm->ipClose;
		$form->latitudeOpen = $recordForm->latitudeOpen;
		$form->longitudeOpen = $recordForm->longitudeOpen;
		$form->latitudeClose = $recordForm->latitudeClose;
		$form->longitudeClose = $recordForm->longitudeClose;
		$form->device = $recordForm->device;

		if ($recordForm->reason !== null)
		{
			$form->getFirstEventForm()->reason = $recordForm->reason;
		}

		// For expired records legacy requires:
		// non-empty reason and explicit end time (recordedStopSeconds / recordedStopTime)
		if ($recordForm->stopTimestamp !== null)
		{
			$stopTimestamp = $recordForm->stopTimestamp;
			if ($stopTimestamp > 0)
			{
				$form->recordedStopTimestamp = $stopTimestamp;

				$userDateTime = TimeHelper::getInstance()->createUserDateTimeFromFormat('U', $stopTimestamp, $userId);
				if ($userDateTime)
				{
					$form->recordedStopDateFormatted = $userDateTime->format('Y-m-d');
					$form->recordedStopTime = $userDateTime->format('H:i');
					$form->recordedStopSeconds =
						((int)$userDateTime->format('H') * 3600)
						+ ((int)$userDateTime->format('i') * 60)
						+ (int)$userDateTime->format('s');
				}
			}
		}

		return $form;
	}

	public function handleSuccessfulExecution(
		int $userId,
		string $eventMessageId,
		WorktimeServiceResult $result,
	): void
	{
		\CUser::setLastActivityDate($userId);
		\CUserReportFull::clearReportCache($userId);

		$fields = $this->recordRepository->convertFieldsToCompatibility(
			$result->getWorktimeRecord(),
		);

		$events = getModuleEvents('timeman', $eventMessageId);
		while ($event = $events->fetch())
		{
			executeModuleEventEx($event, [$fields]);
		}
	}

	public function canManageWorkTimeOnMobile(int $userId): bool
	{
		$schedules = $this->legacyScheduleRepository->findSchedulesByUserId($userId);
		if (empty($schedules))
		{
			return true;
		}

		$canManage = true;
		foreach ($schedules as $schedule)
		{
			if (!Schedule::isDeviceAllowed(ScheduleTable::ALLOWED_DEVICES_MOBILE, $schedule))
			{
				$canManage = false;
				break;
			}
		}

		return $canManage;
	}
}
