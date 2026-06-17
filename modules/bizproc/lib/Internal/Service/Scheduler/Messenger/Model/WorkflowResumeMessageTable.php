<?php

declare(strict_types = 1);

namespace Bitrix\Bizproc\Internal\Service\Scheduler\Messenger\Model;

use Bitrix\Main\Messenger\Internals\Storage\Db\Model\MessengerMessageTable;

/**
 * Class WorkflowResumeMessageTable
 *
 * DO NOT WRITE ANYTHING BELOW THIS
 *
 * <<< ORMENTITYANNOTATION
 * @method static EO_WorkflowResumeMessage_Query query()
 * @method static EO_WorkflowResumeMessage_Result getByPrimary($primary, array $parameters = [])
 * @method static EO_WorkflowResumeMessage_Result getById($id)
 * @method static EO_WorkflowResumeMessage_Result getList(array $parameters = [])
 * @method static EO_WorkflowResumeMessage_Entity getEntity()
 * @method static \Bitrix\Bizproc\Internal\Service\Scheduler\Messenger\Model\EO_WorkflowResumeMessage createObject($setDefaultValues = true)
 * @method static \Bitrix\Bizproc\Internal\Service\Scheduler\Messenger\Model\EO_WorkflowResumeMessage_Collection createCollection()
 * @method static \Bitrix\Bizproc\Internal\Service\Scheduler\Messenger\Model\EO_WorkflowResumeMessage wakeUpObject($row)
 * @method static \Bitrix\Bizproc\Internal\Service\Scheduler\Messenger\Model\EO_WorkflowResumeMessage_Collection wakeUpCollection($rows)
 */
class WorkflowResumeMessageTable extends MessengerMessageTable
{
	#[\Override]
	public static function getTableName(): string
	{
		return 'b_bp_messenger_workflow_resume_message';
	}
}
