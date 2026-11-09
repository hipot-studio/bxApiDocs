<?php

declare(strict_types=1);

namespace Bitrix\Landing\Integration\AiAssistant\Tool\Site;

use Bitrix\AiAssistant\Exceptions\McpException;
use Bitrix\AiAssistant\Facade\TracedLogger;
use Bitrix\Landing\Integration\AiAssistant\Dto\CancelMarkupJobDto;
use Bitrix\Landing\Integration\AiAssistant\Schema\Site\CancelMarkupJobSchema;
use Bitrix\Landing\Integration\AiAssistant\Tool\BaseTool;
use Bitrix\Landing\Integration\AiAssistant\UseCase\CancelMarkupJobHandler;
use Bitrix\Main\Validation\ValidationService;

class CancelMarkupJobTool extends BaseTool
{
	use AiSiteMarkupToolGate;

	public const ACTION_NAME = 'cancel_markup_job';

	public function __construct(
		private readonly CancelMarkupJobHandler $handler,
		private readonly CancelMarkupJobSchema $schema,
		ValidationService $validationService,
		TracedLogger $tracedLogger,
	)
	{
		parent::__construct($validationService, $tracedLogger);
	}

	/**
	 * @return list<string>
	 */
	protected function getMarkupToolGroups(): array
	{
		return [self::MARKUP_TOOL_GROUP_JOB];
	}

	public function getDescription(): string
	{
		return 'Cancels an unfinished markup job by jobId when the user asks to stop or to start over. '
			. 'Idempotent: cancelling a finished, failed or already cancelled job returns cancelled=false '
			. 'without an error. A new submission after the cancel starts a new job.';
	}

	protected function getInputSchemaDefinition(): array
	{
		return $this->schema->build();
	}

	protected function validate(object $dto): void
	{
		parent::validate($dto);

		if (!$dto instanceof CancelMarkupJobDto)
		{
			throw new \InvalidArgumentException('Invalid markup job cancel payload.');
		}

		if ($dto->jobId === '')
		{
			throw new \InvalidArgumentException('jobId is required to cancel the markup job.');
		}
	}

	protected function executeStructured(int $userId, ...$args): array
	{
		$dto = CancelMarkupJobDto::fromArray($args);
		$effectiveUserId = $this->resolveEffectiveUserId($userId);

		try
		{
			$this->validate($dto);

			return $this->runInUserContext(
				$effectiveUserId,
				fn() => $this->handler->handle($dto, $effectiveUserId),
			);
		}
		catch (\Throwable $e)
		{
			throw new McpException(message: $e->getMessage(), previous: $e);
		}
	}
}
