<?php

declare(strict_types=1);

namespace Bitrix\Mail\Integration\MailService;

use Bitrix\Mail\Internal\Service\SourceGeneration\MigrationOperationSchema;
use Bitrix\Mail\Internal\Service\SourceGeneration\MigrationService;
use Bitrix\Main\Error;
use Bitrix\Main\Result;

final class CancellationReadiness
{
	public function __construct(
		private readonly ?MigrationOperationSchema $operationSchema = null,
	)
	{
	}

	public function check(): Result
	{
		$result = new Result();

		try
		{
			if (($this->operationSchema ?? new MigrationOperationSchema())->isReady())
			{
				return $result;
			}
		}
		catch (\Throwable)
		{
			return $result->addError(new Error(
				'The readiness of the portal for a cancellation cannot be established',
				MigrationService::ERROR_READINESS_UNANSWERED,
			));
		}

		return $result->addError(new Error(
			'The migration operation schema of the portal is not built yet',
			MigrationService::ERROR_OPERATION_SCHEMA_MISSING,
		));
	}
}
