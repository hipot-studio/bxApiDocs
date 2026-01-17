<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\Mapper;

use Bitrix\Tasks\V2\Internal\Entity\Task;
use Bitrix\Tasks\V2\Internal\Entity\Template;
use Bitrix\Tasks\V2\Internal\Entity\Template\ReplicateParams;
use Bitrix\Tasks\V2\Internal\Entity\UserCollection;

class TemplateMapper
{
	public function convertFromRecurringTask(Task $task, ReplicateParams $replicateParams): Template
	{
		return new Template(
			task: $task,
			title: $task->title,
			description: $task->description,
			creator: $task->creator,
			responsibleCollection: new UserCollection($task->responsible),
			replicate: true,
			fileIds: $task->fileIds,
			checklist: $task->checklist,
			group: $task->group,
			priority: $task->priority,
			accomplices: $task->accomplices,
			auditors: $task->auditors,
			parent: $task->parent,
			replicateParams: $replicateParams,
		);
	}
}
