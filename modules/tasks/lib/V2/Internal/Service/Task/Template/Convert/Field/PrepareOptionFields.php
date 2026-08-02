<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Task\Template\Convert\Field;

use Bitrix\Tasks\V2\Internal\Entity;
use Bitrix\Tasks\V2\Internal\Service\Task\Template\Convert\Trait\ConfigTrait;

class PrepareOptionFields implements PrepareFieldInterface
{
	use ConfigTrait;

	public function __invoke(Entity\Template $template, Entity\Task $task): Entity\Template
	{
		return $template->cloneWith([
			'needsControl' => $task->needsControl,
			'allowsChangeDeadline' => $task->allowsChangeDeadline,
			'matchesWorkTime' => $task->matchesWorkTime,
			'requireResult' => $task->requireResult,
			'allowsTimeTracking' => $task->allowsTimeTracking,
			'estimatedTime' => $task->estimatedTime,
			'multitask' => $task->isMultitask,
			'dependsOn' => $task->dependsOn,
			'tags' => $task->tags?->toArray(),
		]);
	}
}
