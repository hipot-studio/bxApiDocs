<?php

namespace Bitrix\Tasks\Flow\Kanban\Stages;

use Bitrix\Main\Localization\Loc;
use Bitrix\Tasks\Flow\Integration\BizProc\Robot\Condition;
use Bitrix\Tasks\Flow\Integration\BizProc\Robot\RobotCommand;
use Bitrix\Tasks\Flow\Integration\BizProc\Trigger\TriggerCommand;
use Bitrix\Tasks\Flow\Kanban\AbstractStage;
use Bitrix\Tasks\Internals\Task\Status;
use Bitrix\Tasks\Kanban\Stage;
use Bitrix\Tasks\Kanban\StagesTable;

class ReviewStage extends AbstractStage
{
	protected function getInternalStage(): Stage
	{
		return (new Stage())
			->setTitle(Loc::getMessage('TASKS_FLOW_AUTO_CREATED_GROUP_STAGE_REVIEW'))
			->setSort(300)
			->setColor('FFAB00')
			->setEntityId($this->projectId)
			->setEntityType(StagesTable::WORK_MODE_GROUP);
	}

	protected function getTriggers(): array
	{
		return [
			new TriggerCommand(
				Loc::getMessage('TASKS_FLOW_AUTO_CREATED_GROUP_STAGE_REVIEW_TRIGGER'),
				Status::SUPPOSEDLY_COMPLETED
			),
		];
	}

	protected function getRobots(): array
	{
		return [
			new RobotCommand(
				Loc::getMessage('TASKS_FLOW_AUTO_CREATED_GROUP_STAGE_REVIEW_ROBOT'),
				Status::SUPPOSEDLY_COMPLETED,
				new Condition('TASK_CONTROL', 'Y')
			)
		];
	}
}