<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Task;

use Bitrix\Main\Event;
use Bitrix\Tasks\V2\Internal\Entity\HistoryLog;
use Bitrix\Tasks\V2\Internal\Entity\Task\ElapsedTime;
use Bitrix\Tasks\V2\Internal\Entity\Task\Elapsed\Source;
use Bitrix\Tasks\V2\Internal\Exception\Task\ElapsedTimeException;
use Bitrix\Tasks\V2\Internal\Exception\Task\ElapsedTimeNotFoundException;
use Bitrix\Tasks\V2\Internal\Repository\ElapsedTimeRepositoryInterface;
use Bitrix\Tasks\V2\Internal\Repository\Mapper\ElapsedTimeMapper;
use Bitrix\Tasks\V2\Internal\Repository\TaskLogRepositoryInterface;
use Bitrix\Tasks\V2\Internal\Service\Esg\EgressController;
use Bitrix\Tasks\V2\Internal\Service\Trait\ApplicationErrorTrait;
use Bitrix\Tasks\V2\Public\Command\Task\Tracking\AddElapsedTimeCommand;

class ElapsedTimeService
{
	use ApplicationErrorTrait;

	public function __construct(
		private readonly ElapsedTimeRepositoryInterface $elapsedTimeRepository,
		private readonly TaskLogRepositoryInterface $taskLogRepository,
		private readonly ElapsedTimeMapper $elapsedTimeMapper,
		private readonly EgressController $egressController,
	)
	{

	}

	/**
	 * @throws ElapsedTimeException
	 */
	public function add(ElapsedTime $elapsedTime): array
	{
		$fields = $this->elapsedTimeMapper->mapFromEntity($elapsedTime);

		foreach (GetModuleEvents('tasks', 'OnBeforeTaskElapsedTimeAdd', true) as $moduleEvent)
		{
			if (ExecuteModuleEventEx($moduleEvent, [&$fields]) === false)
			{
				throw new ElapsedTimeException($this->getApplicationError());
			}
		}

		$elapsedTime = $this->elapsedTimeMapper->mapToEntity($fields);

		$currentDuration = $this->elapsedTimeRepository->getSum($elapsedTime->taskId);

		$id = $this->elapsedTimeRepository->save($elapsedTime);

		$log = new HistoryLog(
			userId: $elapsedTime->userId,
			taskId: $elapsedTime->taskId,
			field: 'TIME_SPENT_IN_LOGS',
			fromValue: $currentDuration,
			toValue: $currentDuration + $elapsedTime->seconds,
		);

		$this->taskLogRepository->add($log);

		$event = new Event('tasks', 'OnTaskElapsedTimeAdd', [$id, $fields]);
		$event->send();

		$this->egressController->process(new AddElapsedTimeCommand(
			elapsedTime: $elapsedTime,
		));

		return [$id, $currentDuration];
	}

	public function update(ElapsedTime $elapsedTime): ElapsedTime
	{
		$updatingItem = $this->elapsedTimeRepository->getById($elapsedTime->getId());
		if ($updatingItem === null)
		{
			throw new ElapsedTimeNotFoundException();
		}

		$fields = $this->elapsedTimeMapper->mapFromEntity($elapsedTime);

		$params = [
			$elapsedTime->getId(),
			$this->elapsedTimeMapper->mapFromEntity($updatingItem),
			&$fields,
		];

		foreach (GetModuleEvents('tasks', 'OnBeforeTaskElapsedTimeUpdate', true) as $moduleEvent)
		{
			if (ExecuteModuleEventEx($moduleEvent, $params) === false)
			{
				throw new ElapsedTimeException($this->getApplicationError());
			}
		}

		$elapsedTime = $this->elapsedTimeMapper->mapToEntity($fields);

		if (
			(int)$updatingItem->minutes !== (int)$elapsedTime->minutes
			|| (int)$updatingItem->seconds !== (int)$elapsedTime->seconds
		)
		{
			$elapsedTime->source = Source::Manual;
		}

		$elapsedTime = $elapsedTime->cloneWith(['taskId' => $updatingItem->taskId]); // cannot change taskId

		$currentDuration = $this->elapsedTimeRepository->getSum($elapsedTime->taskId);

		$id = $this->elapsedTimeRepository->save($elapsedTime);

		$log = new HistoryLog(
			userId: $elapsedTime->userId,
			taskId: $elapsedTime->taskId,
			field: 'TIME_SPENT_IN_LOGS',
			fromValue: $currentDuration,
			toValue: $currentDuration - $updatingItem->seconds + $elapsedTime->seconds,
		);

		$this->taskLogRepository->add($log);

		$event = new Event('tasks', 'OnTaskElapsedTimeUpdate', [$id, $fields]);
		$event->send();

		return $elapsedTime;
	}

	/**
	 * @throws ElapsedTimeException
	 * @throws ElapsedTimeNotFoundException
	 */
	public function delete(ElapsedTime $elapsedTime): bool
	{
		$elapsedTimeId = $elapsedTime->getId();

		if ($elapsedTimeId === null)
		{
			throw new ElapsedTimeNotFoundException('Elapsed time id is null');
		}

		$removingItem = $this->elapsedTimeRepository->getById($elapsedTime->getId());

		if ($removingItem === null)
		{
			throw new ElapsedTimeNotFoundException();
		}

		$fields = $this->elapsedTimeMapper->mapFromEntity($removingItem);

		foreach (GetModuleEvents('tasks', 'OnBeforeTaskElapsedTimeDelete', true) as $moduleEvent)
		{
			if (ExecuteModuleEventEx($moduleEvent, [$removingItem->getId(), $fields]) === false)
			{
				throw new ElapsedTimeException($this->getApplicationError());
			}
		}

		$this->elapsedTimeRepository->delete($removingItem->getId());

		$currentDuration = $this->elapsedTimeRepository->getSum($removingItem->taskId);

		$log = new HistoryLog(
			userId: $removingItem->userId,
			taskId: $removingItem->taskId,
			field: 'TIME_SPENT_IN_LOGS',
			fromValue: $currentDuration + (int)$removingItem->seconds,
			toValue: $currentDuration,
		);

		$this->taskLogRepository->add($log);

		$event = new Event('tasks', 'OnTaskElapsedTimeDelete', [$removingItem->getId(), $fields]);
		$event->send();

		return true;
	}
}
