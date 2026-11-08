<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Public\Provider;

use Bitrix\Main\Type\DateTime;
use Bitrix\Tasks\Internals\Task\Status;
use Bitrix\Tasks\Internals\TaskTable;
use Bitrix\Tasks\V2\Public\Entity\TaskState;

class TaskStateProvider
{
	/**
	 * Returns a fresh lightweight snapshot of the task state.
	 *
	 * @param int $taskId
	 * @return TaskState|null
	 */
	public function getCurrent(int $taskId): ?TaskState
	{
		$task = TaskTable::query()
			->setSelect(['STATUS', 'DEADLINE', 'END_DATE_PLAN'])
			->where('ID', $taskId)
			->setLimit(1)
			->fetch()
		;
		if ($task === false)
		{
			return null;
		}

		$status = (int)$task['STATUS'];
		$deadlineTs = $this->mapTimestamp($task['DEADLINE']);

		return new TaskState(
			status: $status,
			isExpired: $this->isExpired($status, $deadlineTs),
			deadlineTs: $deadlineTs,
			endPlanTs: $this->mapTimestamp($task['END_DATE_PLAN']),
		);
	}

	private function mapTimestamp(mixed $dateTime): ?int
	{
		return $dateTime instanceof DateTime ? $dateTime->getTimestamp() : null;
	}

	private function isExpired(int $status, ?int $deadlineTs): bool
	{
		$finishedStatuses = [
			Status::SUPPOSEDLY_COMPLETED,
			Status::COMPLETED,
			Status::DEFERRED,
		];

		return $deadlineTs !== null
			&& !in_array($status, $finishedStatuses, true)
			&& $deadlineTs <= time()
		;
	}
}
