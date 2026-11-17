<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Infrastructure\Rest\Exception;

use Bitrix\Rest\V3\Exception\RestException;
use Bitrix\Rest\V3\Exception\SkipWriteToLogException;
use Bitrix\Tasks\Dispatcher\PublicAction\Task\Stages;
use Bitrix\Tasks\Control\Exception\TaskStageValidationException;

class StageValidationException extends RestException implements SkipWriteToLogException
{
	public function __construct(
		private readonly TaskStageValidationException $cause,
	)
	{
		parent::__construct();
	}

	public function getRegistryCode(): string
	{
		return match ($this->cause->getErrorCode())
		{
			TaskStageValidationException::ERROR_DIFFERENT_STAGES => 'TASKS_STAGE_DIFFERENT_STAGES',
			default => 'TASKS_STAGE_NOT_FOUND',
		};
	}

	protected function getMessagePhraseCode(): string
	{
		return match ($this->cause->getErrorCode())
		{
			TaskStageValidationException::ERROR_DIFFERENT_STAGES => 'STAGES_ERROR_DIFFERENT_STAGES',
			default => 'STAGES_ERROR_NOT_FOUND',
		};
	}

	protected function getClassWithPhrase(): string
	{
		return Stages::class;
	}
}
