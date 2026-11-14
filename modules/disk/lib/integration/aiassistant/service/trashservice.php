<?php

declare(strict_types=1);

namespace Bitrix\Disk\Integration\AiAssistant\Service;

use Bitrix\AiAssistant\Exceptions\McpException;
use Bitrix\Disk\Internals\VolumeTable;
use Bitrix\Disk\Storage;
use Bitrix\Disk\Volume\Base;
use Bitrix\Disk\Volume\Cleaner;
use Bitrix\Disk\Volume\Storage\TrashCan;
use Bitrix\Disk\Volume\Task;

final class TrashService
{
	private \Closure $workerStarter;

	public function __construct(?callable $workerStarter = null)
	{
		$this->workerStarter = $workerStarter !== null
			? \Closure::fromCallable($workerStarter)
			: static fn(array $params): bool => Cleaner::addWorker($params)
		;
	}

	public function scheduleEmptyTrash(int $userId, Storage $storage): array
	{
		$indicator = new TrashCan();
		$indicator
			->setOwner($userId)
			->addFilter('STORAGE_ID', $storage->getId())
			->purify()
		;
		$taskResult = VolumeTable::add([
			'INDICATOR_TYPE' => TrashCan::class,
			'OWNER_ID' => $userId,
			'STORAGE_ID' => $storage->getId(),
		]);
		if (!$taskResult->isSuccess())
		{
			throw new McpException('Could not create the exact trash measurement task.');
		}

		$taskId = (int)$taskResult->getId();
		$indicator
			->setFilterId($taskId)
			->measure([Base::DISK_FILE])
		;
		$task = VolumeTable::getById($taskId)->fetch();
		if (
			!$task
			|| $task['INDICATOR_TYPE'] !== TrashCan::class
			|| (int)$task['OWNER_ID'] !== $userId
			|| (int)$task['STORAGE_ID'] !== (int)$storage->getId()
		)
		{
			throw new McpException('Could not create the exact trash measurement task.');
		}

		$params = [
			'delay' => 5,
			'filterId' => $taskId,
			'ownerId' => $userId,
			'storageId' => (int)$storage->getId(),
			Task::DROP_TRASHCAN => true,
		];
		try
		{
			$scheduled = ($this->workerStarter)($params);
		}
		catch (\Throwable)
		{
			$taskRow = VolumeTable::getById($taskId)->fetch();

			return [
				'taskId' => $taskId,
				'status' => $taskRow ? $this->formatStatus((int)$taskRow['AGENT_LOCK']) : 'unknown',
				'scheduled' => false,
				'retrySafe' => false,
				'resultStatus' => 'unknown_result',
			];
		}

		$taskRow = VolumeTable::getById($taskId)->fetch();

		return [
			'taskId' => $taskId,
			'status' => $taskRow ? $this->formatStatus((int)$taskRow['AGENT_LOCK']) : 'unknown',
			'scheduled' => (bool)$scheduled,
			'retrySafe' => !$scheduled,
			'resultStatus' => $scheduled ? 'accepted' : 'failed',
		];
	}

	private function formatStatus(int $status): string
	{
		return match ($status)
		{
			Task::TASK_STATUS_NONE => 'created',
			Task::TASK_STATUS_WAIT => 'waiting',
			Task::TASK_STATUS_RUNNING => 'running',
			Task::TASK_STATUS_DONE => 'done',
			Task::TASK_STATUS_CANCEL => 'cancelled',
			default => 'unknown',
		};
	}
}
