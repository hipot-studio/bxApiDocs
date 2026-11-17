<?php

namespace Bitrix\Disk\Controller;

use Bitrix\Disk;
use Bitrix\Disk\Internal\Service\Audit\AuditTrashcanEventProducer;
use Bitrix\Disk\Internal\Service\Audit\AuditTransaction;
use Bitrix\Disk\Internals\Engine;

final class TrashCan extends Engine\Controller
{
	/**
	 * Returns default pre-filters for action.
	 * @return array
	 */
	protected function getDefaultPreFilters()
	{
		$defaultPreFilters = parent::getDefaultPreFilters();
		$defaultPreFilters[] = new Engine\ActionFilter\CheckReadPermission();

		return $defaultPreFilters;
	}

	public function emptyAction(Disk\Storage $storage)
	{
		// the cleaner skips every object the user may not destroy, so without the right on the root it drops nothing
		$securityContext = $storage->getSecurityContext($this->getCurrentUser()->getId());
		if (!$storage->getRootObject()?->canDelete($securityContext))
		{
			$this->addDeniedError();

			return;
		}

		// a repeated request must not measure the trash can anew: it would replace the queued task
		// and the progress of the owner would count the same objects twice
		if ($this->hasActiveTask($storage))
		{
			Disk\Volume\Cleaner::addWorker();

			return;
		}

		$indicator = new Disk\Volume\Storage\TrashCan();
		$indicator
			->setOwner($this->getCurrentUser()->getId())
			->addFilter('STORAGE_ID', $storage->getId())
			->purify()
			->measure([
				Disk\Volume\Base::DISK_FILE
		  	])
		;

		$task = $indicator->getMeasurementResult()->fetch();
		$taskId = $task['ID'];

		if (!Disk\Volume\Cleaner::addWorker())
		{
			return;
		}

		$task = new Disk\Volume\Task();
		if (!$task->loadTaskById($taskId, $this->getCurrentUser()->getId()))
		{
			return;
		}

		$this->acceptTaskWithAudit(
			$task,
			new AuditTrashcanEventProducer(),
			(int)$storage->getId(),
			(int)$this->getCurrentUser()->getId(),
		);
	}

	private function hasActiveTask(Disk\Storage $storage): bool
	{
		$task = Disk\Internals\VolumeTable::getList([
			'select' => ['ID'],
			'filter' => [
				'=INDICATOR_TYPE' => Disk\Volume\Storage\TrashCan::className(),
				'=OWNER_ID' => (int)$this->getCurrentUser()->getId(),
				'=STORAGE_ID' => (int)$storage->getId(),
				'=AGENT_LOCK' => [
					Disk\Volume\Task::TASK_STATUS_WAIT,
					Disk\Volume\Task::TASK_STATUS_RUNNING,
				],
			],
			'limit' => 1,
		])->fetch();

		return $task !== false;
	}

	private function acceptTaskWithAudit(
		Disk\Volume\Task $task,
		AuditTrashcanEventProducer $producer,
		int $storageId,
		int $userId,
	): bool
	{
		return AuditTransaction::run(function (AuditTransaction $transaction) use (
			$task,
			$producer,
			$storageId,
			$userId,
		): bool {
			$task->setStatus(Disk\Volume\Task::TASK_STATUS_WAIT);
			$task->setStatusSubTask(
				Disk\Volume\Task::DROP_TRASHCAN,
				Disk\Volume\Task::TASK_STATUS_WAIT,
			);
			if (!$task->fixState())
			{
				return false;
			}

			$auditResult = $producer->publishEmptied($storageId, $userId);
			if (!$auditResult->isSuccess())
			{
				$this->addErrors($auditResult->getErrors());

				return false;
			}
			$transaction->addAfterCommit(
				static fn(): int => Disk\Volume\Cleaner::countWorker($userId),
			);

			return true;
		});
	}
}
