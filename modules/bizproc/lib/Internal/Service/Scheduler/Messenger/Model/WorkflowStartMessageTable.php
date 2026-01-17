<?php

declare(strict_types = 1);

namespace Bitrix\Bizproc\Internal\Service\Scheduler\Messenger\Model;

use Bitrix\Main\Messenger\Internals\Storage\Db\Model\MessengerMessageTable;

class WorkflowStartMessageTable extends MessengerMessageTable
{
	#[\Override]
	public static function getTableName(): string
	{
		return 'b_bp_messenger_workflow_start_message';
	}
}
