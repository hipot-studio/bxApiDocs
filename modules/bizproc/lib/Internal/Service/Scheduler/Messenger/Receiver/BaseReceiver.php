<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Service\Scheduler\Messenger\Receiver;

use Bitrix\Main\Application;
use Bitrix\Main\Messenger\Entity\MessageInterface;
use Bitrix\Main\Messenger\Internals\Exception\Receiver\RecoverableMessageException;
use Bitrix\Main\Messenger\Internals\Exception\Receiver\UnrecoverableMessageException;
use Bitrix\Main\Messenger\Receiver\AbstractReceiver;

class BaseReceiver extends AbstractReceiver
{
	protected function process(MessageInterface $message): void
	{
	}

	public function handleException(\Throwable $exception): void
	{
		$this->logIfNeeded($exception);

		throw $this->mapException($exception);
	}

	private function shouldLog(\Throwable $exception): bool
	{
		$codeList = [
			\CBPRuntime::EXCEPTION_CODE_INSTANCE_NOT_FOUND,
			\CBPRuntime::EXCEPTION_CODE_INSTANCE_TARIFF_LIMIT_EXCEED,
			\CBPRuntime::EXCEPTION_CODE_INSTANCE_LOCKED,
		];

		return !in_array($exception->getCode(), $codeList, true);
	}

	private function logIfNeeded(\Throwable $exception): void
	{
		if ($this->shouldLog($exception))
		{
			Application::getInstance()
				->getExceptionHandler()
				->writeToLog($exception)
			;
		}
	}

	private function mapException(\Throwable $exception): \Throwable
	{
		return match ($exception->getCode())
		{
			\CBPRuntime::EXCEPTION_CODE_INSTANCE_LOCKED => new RecoverableMessageException(previous: $exception),
			default => new UnrecoverableMessageException(previous: $exception),
		};
	}
}
