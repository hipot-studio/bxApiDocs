<?php

declare(strict_types=1);

namespace Bitrix\Tasks\Control\Exception;

class TaskStageValidationException extends TaskUpdateException
{
	public const ERROR_NOT_FOUND = 'NOT_FOUND';
	public const ERROR_DIFFERENT_STAGES = 'DIFFERENT_STAGES';

	public function __construct(
		string $message,
		private readonly string $errorCode,
	)
	{
		parent::__construct($message);
	}

	public function getErrorCode(): string
	{
		return $this->errorCode;
	}
}
