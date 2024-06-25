<?php

namespace Bitrix\Tasks\Flow\Integration\BizProc;

use Bitrix\Tasks\Integration\Bizproc\Document\Task;

trait DocumentTrait
{
	public function getDocumentType(int $projectId): array
	{
		return ['tasks', Task::class, Task::resolveProjectTaskType($projectId)];
	}
}