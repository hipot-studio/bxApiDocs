<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\Audit;

use Bitrix\Disk\Internal\Entity;
use Bitrix\Main\Result;
use RuntimeException;
use Throwable;

final class AuditCaptureService
{
	public function __construct(
		private readonly ?AuditOutboxService $outboxService = null,
		private readonly ?AuditCaptureValidator $validator = null,
	)
	{
	}

	/**
	 * @param string[] $allowedDetailKeys
	 */
	public function capture(Entity\Audit\AuditMessage $message, array $allowedDetailKeys = []): Result
	{
		return self::runFailOpen(function () use ($message, $allowedDetailKeys): Result {
			$validationResult = $this->getValidator()->validate($message, $allowedDetailKeys);
			if (!$validationResult->isSuccess())
			{
				return $validationResult;
			}

			return $this->getOutboxService()->enqueue($message);
		});
	}

	public static function runFailOpen(callable $callback): Result
	{
		try
		{
			$result = $callback();
			if (!$result->isSuccess())
			{
				self::writeToLog(new RuntimeException(implode('; ', $result->getErrorMessages())));
			}
		}
		catch (Throwable $exception)
		{
			self::writeToLog($exception);
		}

		return new Result();
	}

	private function getOutboxService(): AuditOutboxService
	{
		return $this->outboxService ?? new AuditOutboxService();
	}

	private function getValidator(): AuditCaptureValidator
	{
		return $this->validator ?? new AuditCaptureValidator();
	}

	private static function writeToLog(Throwable $exception): void
	{
		try
		{
			\Bitrix\Main\Application::getInstance()->getExceptionHandler()->writeToLog($exception);
		}
		catch (Throwable)
		{
		}
	}
}
