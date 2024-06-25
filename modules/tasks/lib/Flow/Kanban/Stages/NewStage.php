<?php

namespace Bitrix\Tasks\Flow\Kanban\Stages;

use Bitrix\Main\Localization\Loc;
use Bitrix\Tasks\Flow\Integration\BizProc\TriggerCommand;
use Bitrix\Tasks\Flow\Kanban\AbstractStage;
use Bitrix\Tasks\Integration\Bizproc\Document\Task;
use Bitrix\Tasks\Internals\Task\Status;
use Bitrix\Tasks\Kanban\Stage;
use Bitrix\Tasks\Kanban\StagesTable;

class NewStage extends AbstractStage
{
	protected function getInternalStage(): Stage
	{
		return (new Stage())
			->setTitle(Loc::getMessage('TASKS_FLOW_AUTO_CREATED_GROUP_STAGE_NEW'))
			->setSort(100)
			->setColor(StagesTable::SYS_TYPE_NEW_COLOR)
			->setSystemType(StagesTable::SYS_TYPE_NEW)
			->setEntityId($this->projectId)
			->setEntityType(StagesTable::WORK_MODE_GROUP);
	}

	protected function getTriggers(): array
	{
		return [];
	}

	protected function getRobots(): array
	{
		return [];
	}
}