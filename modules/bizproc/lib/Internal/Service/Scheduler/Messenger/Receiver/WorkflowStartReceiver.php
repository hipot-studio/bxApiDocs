<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Service\Scheduler\Messenger\Receiver;

use Bitrix\Bizproc\Internal\Service\Scheduler\Messenger\Entity\WorkflowStartMessage;
use Bitrix\Main\Messenger\Entity\MessageInterface;
use Bitrix\Main\Messenger\Receiver\AbstractReceiver;

class WorkflowStartReceiver extends AbstractReceiver
{
	/**
	 * @param WorkflowStartMessage $message
	 */
	protected function process(MessageInterface $message): void
	{
		\CBPRuntime::startDelayedWorkflow($message->workflowId);
	}
}
