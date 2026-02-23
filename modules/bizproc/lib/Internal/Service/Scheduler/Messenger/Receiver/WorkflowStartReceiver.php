<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Service\Scheduler\Messenger\Receiver;

use Bitrix\Bizproc\Internal\Service\Scheduler\Messenger\Entity\WorkflowStartMessage;
use Bitrix\Main\Messenger\Entity\MessageInterface;
use Bitrix\Main\Messenger\Internals\Exception\Receiver\UnprocessableMessageException;
use Bitrix\Main\Messenger\Internals\Exception\Receiver\UnrecoverableMessageException;
use Bitrix\Main\Messenger\Internals\Exception\Receiver\RecoverableMessageException;
use Bitrix\Main\Messenger\Receiver\AbstractReceiver;

class WorkflowStartReceiver extends AbstractReceiver
{
	/**
	 * @param WorkflowStartMessage $message
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
			if ($e->getCode() === \CBPRuntime::EXCEPTION_CODE_INSTANCE_LOCKED)
			{
				throw new RecoverableMessageException(previous: $e);
			}

			if (
				$e->getCode() !== \CBPRuntime::EXCEPTION_CODE_INSTANCE_NOT_FOUND
				&& $e->getCode() !== \CBPRuntime::EXCEPTION_CODE_INSTANCE_TARIFF_LIMIT_EXCEED
			)
			{
				$this->logUnknownException($e);
			}

			throw new UnrecoverableMessageException(previous: $e);
		}
	}

	private function logUnknownException(\Exception $exception): void
	{
		\Bitrix\Main\Application::getInstance()->getExceptionHandler()->writeToLog($exception);
	}
}
