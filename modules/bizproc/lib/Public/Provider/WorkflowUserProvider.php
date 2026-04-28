<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Public\Provider;

use Bitrix\Bizproc\Workflow\Entity\WorkflowUserTable;

class WorkflowUserProvider
{
	public function getUserIdsByWorkflowId(string $workflowId): array
	{
		return WorkflowUserTable::getUserIdsByWorkflowId($workflowId);
	}
}
