<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Service\User;

use Bitrix\Main\Application;
use Bitrix\Main\Result;

class UserInitializationErrorLogger
{
	private \Closure $exceptionWriter;

	public function __construct(?\Closure $exceptionWriter = null)
	{
		$this->exceptionWriter = $exceptionWriter ?? static function (\Throwable $exception): void {
			Application::getInstance()->getExceptionHandler()->writeToLog($exception);
		};
	}

	public function logResultErrors(Result $result, int $userId): void
	{
		foreach ($result->getErrors() as $error)
		{
			$code = $error->getCode();
			$this->logThrowable(new \RuntimeException(
				$this->formatMessage($error->getMessage(), $userId),
				is_int($code) ? $code : 0,
			));
		}
	}

	public function logThrowable(\Throwable $exception, ?int $userId = null): void
	{
		if ($userId !== null)
		{
			$exception = new \RuntimeException(
				$this->formatMessage($exception->getMessage(), $userId),
				is_int($exception->getCode()) ? $exception->getCode() : 0,
				$exception,
			);
		}

		($this->exceptionWriter)($exception);
	}

	private function formatMessage(string $message, int $userId): string
	{
		return "User initialization failed for user {$userId}: {$message}";
	}
}
