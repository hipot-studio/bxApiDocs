<?php

declare(strict_types=1);

namespace Bitrix\Timeman\V2\Public\Command\RecordIntent;

use Bitrix\Main\Result;
use Bitrix\Timeman\V2\Internal\Repository\RecordIntentStateRepository;
use Bitrix\Timeman\V2\Internal\Service\RecordIntentService;
use Bitrix\Timeman\V2\Public\Dto\RecordIntent\RecordIntentSchedule;

class SuppressPeriodCommandHandler
{
	public function __construct(
		private readonly RecordIntentService $service,
		private readonly RecordIntentStateRepository $stateRepository,
	)
	{
	}

	public function __invoke(SuppressPeriodCommand $command): Result
	{
		// The verdict behind the suppression is made for the local day of the user, so the window is
		// closed for that day and not for the period, whose bounds are those of a shift.
		$suppressResult = $this->stateRepository->suppressPeriod(
			$command->userId,
			$command->periodKey,
			$this->service->getNextLocalDayStart($command->userId),
		);

		if (!$suppressResult->isSuccess())
		{
			return $suppressResult;
		}

		$result = new Result();
		$result->setData([
			'schedule' => $this->resolveAnsweredSchedule($command, $suppressResult->getData()),
		]);

		return $result;
	}

	/**
	 * Everything except the closed window is already known to whoever asked for the suppression: the
	 * caller resolves the schedule in the same request, so resolving it again here would read the
	 * intent state, the schedules, the shifts and the history of the day starts a second time. The
	 * new bound is taken from the row the write left behind and not from the value sent into it: a
	 * period already suppressed further ahead keeps its own bound.
	 *
	 * @param SuppressPeriodCommand $command
	 * @param array $state row of the intent state after the write
	 * @return RecordIntentSchedule
	 */
	private function resolveAnsweredSchedule(SuppressPeriodCommand $command, array $state): RecordIntentSchedule
	{
		if ($command->schedule === null)
		{
			return RecordIntentSchedule::mapFromArray($this->service->resolveSchedule($command->userId));
		}

		return RecordIntentSchedule::mapFromArray(array_merge(
			$command->schedule->toArray(),
			['suppressedUntilTimestamp' => (int)($state['SUPPRESSED_UNTIL'] ?? 0)],
		));
	}
}
