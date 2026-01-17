<?php

namespace Bitrix\Tasks\Integration\Disk\Connector\Task;

use Bitrix\Main\Localization\Loc;
use Bitrix\Tasks\Access\ActionDictionary;
use Bitrix\Tasks\Access\ResultAccessController;
use Bitrix\Tasks\Integration\Disk\Connector\Task;
use Bitrix\Tasks\V2\Internal\DI\Container;
use Bitrix\Tasks\V2\Internal\Entity\User;
use Bitrix\Tasks\V2\Internal\Repository\Task\Select;
use CComponentEngine;

class Result extends Task
{
	protected ?int $taskId = null;

	public function canRead($userId): bool
	{
		if ($this->canRead === null)
		{
			$this->canRead = ResultAccessController::can(
				(int)$userId,
				ActionDictionary::ACTION_RESULT_READ,
				$this->entityId
			);
		}

		return $this->canRead;
	}

	public function canUpdate($userId): bool
	{
		return false;
	}

	private function getTaskId(): ?int
	{
		if ($this->taskId === null)
		{
			$resultRepository = Container::getInstance()->getResultRepository();

			$this->taskId = $resultRepository->getById((int)$this->entityId)?->taskId;
		}

		return $this->taskId;
	}

	protected function loadTaskData($userId): array
	{
		if ($this->taskPostData === null)
		{
			$taskReadRepository = Container::getInstance()->getTaskReadRepository();

			$task = $taskReadRepository->getById($this->getTaskId(), new Select(members: true));
			if ($task === null)
			{
				return [];
			}

			$this->taskPostData = [
				'ID' => $task->id,
				'TITLE' => $task->title,
				'RESPONSIBLE_ID' => $task->responsible?->id,
				'CREATED_BY' => $task->creator?->id,
				'RESPONSIBLE' => $task->responsible,
				'CREATOR' => $task->creator,
			];
		}

		return $this->taskPostData;
	}

	protected function getDestinations(): array
	{
		if ($this->taskPostData === null)
		{
			return [];
		}

		$creator = $this->taskPostData['CREATOR'] ?? null;
		$responsible = $this->taskPostData['RESPONSIBLE'] ?? null;

		if ($responsible === null || $creator === null)
		{
			return [];
		}

		$destinations = [];
		foreach ([$creator, $responsible] as $member)
		{
			/** @var User $member */
			$destinations[$member->id] = [
				'NAME' => $member->name,
				'LINK' => CComponentEngine::makePathFromTemplate($this->getPathToUser(), ['user_id' => $member->getId()]),
				'AVATAR_SRC' => $member->image?->src,
				'IS_EXTRANET' => 'N',
			];
		}

		return $destinations;
	}

	protected function getTitle(): string
	{
		return (string)Loc::getMessage('DISK_UF_TASK_RESULT_CONNECTOR_TITLE', ['#ID#' => $this->getTaskId()]);
	}

	public function getPathToTask(): string
	{
		return $this::$pathToTask . '?RID=' . $this->entityId;
	}
}
