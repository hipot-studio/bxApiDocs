<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Service\Scheduler\Messenger\Receiver;

use Bitrix\Bizproc\Internal\Service\Scheduler\Messenger\Entity\WorkflowResumeMessage;
use Bitrix\Main\Messenger\Entity\MessageInterface;
use Bitrix\Main\Messenger\Internals\Exception\Receiver\RecoverableMessageException;
use Bitrix\Main\Messenger\Internals\Exception\Receiver\UnprocessableMessageException;
use Bitrix\Main\Messenger\Internals\Exception\Receiver\UnrecoverableMessageException;

class WorkflowResumeReceiver extends BaseReceiver
{
	/**
	 * @param WorkflowResumeMessage $message
	 * @return void
	 * @throws RecoverableMessageException
	 * @throws UnrecoverableMessageException
	 */
	protected function process(MessageInterface $message): void
	{
		if (!($message instanceof WorkflowResumeMessage))
		{
			throw new UnprocessableMessageException($message);
		}

		try
		{
			\CBPRuntime::sendExternalEvent($message->workflowId, $message->eventName);
		}
		catch (\Exception $e)
		{
			$this->handleException($e);
		}
	}
}
