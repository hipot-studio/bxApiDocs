<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Service\Scheduler\Messenger\Receiver;

use Bitrix\Bizproc\Internal\Service\Scheduler\Messenger\Entity\WorkflowStartMessage;
use Bitrix\Main\Messenger\Entity\MessageInterface;
use Bitrix\Main\Messenger\Internals\Exception\Receiver\UnprocessableMessageException;
use Bitrix\Main\Messenger\Internals\Exception\Receiver\UnrecoverableMessageException;
use Bitrix\Main\Messenger\Internals\Exception\Receiver\RecoverableMessageException;

class WorkflowStartReceiver extends BaseReceiver
{
	/**
	 * @param WorkflowStartMessage $message
	 * @return void
	 * @throws RecoverableMessageException
	 * @throws UnrecoverableMessageException
	 */
	protected function process(MessageInterface $message): void
	{
		if (!($message instanceof WorkflowStartMessage))
		{
			throw new UnprocessableMessageException($message);
		}

		try
		{
			\CBPRuntime::startDelayedWorkflow($message->workflowId);
		}
		catch (\Exception $e)
		{
			$this->handleException($e);
		}
	}
}
