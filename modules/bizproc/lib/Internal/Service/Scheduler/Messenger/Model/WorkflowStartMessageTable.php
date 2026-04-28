<?php

declare(strict_types = 1);

namespace Bitrix\Bizproc\Internal\Service\Scheduler\Messenger\Model;

use Bitrix\Main\Messenger\Internals\Storage\Db\Model\MessengerMessageTable;

/**
 * Class WorkflowStartMessageTable
 *
 * DO NOT WRITE ANYTHING BELOW THIS
 *
 * <<< ORMENTITYANNOTATION
 * @method static EO_WorkflowStartMessage_Query query()
 * @method static EO_WorkflowStartMessage_Result getByPrimary($primary, array $parameters = [])
 * @method static EO_WorkflowStartMessage_Result getById($id)
 * @method static EO_WorkflowStartMessage_Result getList(array $parameters = [])
 * @method static EO_WorkflowStartMessage_Entity getEntity()
 * @method static \Bitrix\Bizproc\Internal\Service\Scheduler\Messenger\Model\EO_WorkflowStartMessage createObject($setDefaultValues = true)
 * @method static \Bitrix\Bizproc\Internal\Service\Scheduler\Messenger\Model\EO_WorkflowStartMessage_Collection createCollection()
 * @method static \Bitrix\Bizproc\Internal\Service\Scheduler\Messenger\Model\EO_WorkflowStartMessage wakeUpObject($row)
 * @method static \Bitrix\Bizproc\Internal\Service\Scheduler\Messenger\Model\EO_WorkflowStartMessage_Collection wakeUpCollection($rows)
 */
class WorkflowStartMessageTable extends MessengerMessageTable
{
	#[\Override]
	public static function getTableName(): string
	{
		return 'b_bp_messenger_workflow_start_message';
	}
}
