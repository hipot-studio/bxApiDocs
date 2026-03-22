<?php

declare(strict_types=1);

namespace Bitrix\Crm\Integration\Tasks\Service;

use Bitrix\Crm\Automation\Trigger\TaskStatusTrigger;
use Bitrix\Tasks\Integration\CRM\Timeline\Bindings;

class TriggerService
{
	public function executeTriggers(Bindings $bindings, int $taskId, int $status): void
	{
		TaskStatusTrigger::execute(
			$bindings->toArray('OWNER_ID', 'OWNER_TYPE_ID'),
			['TASK' => [
				'ID' => $taskId,
				'REAL_STATUS' => $status,
			]],
		);
	}
}
