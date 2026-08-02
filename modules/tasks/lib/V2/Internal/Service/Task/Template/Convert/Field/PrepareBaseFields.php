<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Task\Template\Convert\Field;

use Bitrix\Tasks\V2\Internal\Entity;
use Bitrix\Tasks\V2\Internal\Service\Task\Template\Convert\Trait\ConfigTrait;

class PrepareBaseFields implements PrepareFieldInterface
{
	use ConfigTrait;

	public function __invoke(Entity\Template $template, Entity\Task $task): Entity\Template
	{
		return $template->cloneWith([
			'task' => $task,
			'title' => $task->title,
			'description' => $task->description,
			'creator' => $task->creator,
			'responsibleCollection' => $task->responsible === null
				? null
				: new Entity\UserCollection($task->responsible),
			'group' => $task->group,
			'parent' => $task->parent,
			'priority' => $task->priority,
			'fileIds' => $task->fileIds,
			'accomplices' => $task->accomplices,
			'auditors' => $task->auditors,
			'crmItemIds' => $task->crmItemIds,
			'userFields' => $task->userFields,
			'allowsTimeTracking' => $task->allowsTimeTracking,
			'needsControl' => $task->needsControl,
			'allowsChangeDeadline' => $task->allowsChangeDeadline,
			'matchesWorkTime' => $task->matchesWorkTime,
		]);
	}
}
