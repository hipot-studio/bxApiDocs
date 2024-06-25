<?php

namespace Bitrix\Tasks\Flow\Kanban;

use Bitrix\Main\ORM\Data\Result;
use Bitrix\Tasks\Flow\Integration\BizProc\Robot\RobotCommand;
use Bitrix\Tasks\Flow\Integration\BizProc\Robot\RobotService;
use Bitrix\Tasks\Flow\Integration\BizProc\Trigger\TriggerCommand;
use Bitrix\Tasks\Flow\Integration\BizProc\Trigger\TriggerService;
use Bitrix\Tasks\Kanban\Stage;

abstract class AbstractStage
{
	protected int $id;
	protected int $projectId;
	protected int $userId;

	abstract protected function getInternalStage(): Stage;

	/**
	 * @return TriggerCommand[]
	 */
	abstract protected function getTriggers(): array;

	/**
	 * @return RobotCommand[]
	 */
	abstract protected function getRobots(): array;

	public function __construct(int $projectId, int $userId)
	{
		$this->projectId = $projectId;
		$this->userId = $userId;
	}

	public function save(): Result
	{
		$result = $this->getInternalStage()->save();
		if (!$result->isSuccess())
		{
			return $result;
		}

		$this->id = $result->getId();

		$this->saveTriggers();

		$this->saveRobots();

		return $result;
	}

	private function saveTriggers(): void
	{
		$triggers = $this->getTriggers();
		$service = (new TriggerService($this->id, $this->projectId));

		$service->add(...$triggers);
	}

	private function saveRobots(): void
	{
		$robots = $this->getRobots();
		$service = (new RobotService($this->id, $this->projectId, $this->userId));

		$service->add(...$robots);
	}
}