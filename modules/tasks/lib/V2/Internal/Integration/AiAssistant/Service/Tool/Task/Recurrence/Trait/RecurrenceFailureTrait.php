<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\Tool\Task\Recurrence\Trait;

use Bitrix\AiAssistant\Exceptions\McpException;
use Bitrix\Main\Result;
use Bitrix\Tasks\Internals\Log\LogFacade;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Exception\DtoValidationException;
use Throwable;

/**
 * Single failure policy for the recurrence tools: input validation messages are ours and are safe to
 * expose, everything else is logged and answered with a fixed message.
 */
trait RecurrenceFailureTrait
{
	public const ACCESS_DENIED_MESSAGE = 'Access denied.';
	public const SERVICE_FAILURE_MESSAGE = 'The action could not be completed because of an internal error.';

	protected function logFailure(Throwable $throwable): void
	{
		LogFacade::logThrowable($throwable);
	}

	protected function createServiceFailureResponse(Throwable $throwable): string
	{
		$this->logFailure($throwable);

		return $this->createFailureResponse(self::SERVICE_FAILURE_MESSAGE);
	}

	protected function createResultFailureResponse(Result $result): string
	{
		LogFacade::logErrors($result->getErrorCollection());

		return $this->createFailureResponse(self::SERVICE_FAILURE_MESSAGE);
	}

	protected function createServiceFailureException(Throwable $throwable): McpException
	{
		$this->logFailure($throwable);

		return new McpException(self::SERVICE_FAILURE_MESSAGE, previous: $throwable);
	}

	protected function createValidationFailureException(DtoValidationException $exception): McpException
	{
		return new McpException($exception->getMessage(), previous: $exception);
	}
}
