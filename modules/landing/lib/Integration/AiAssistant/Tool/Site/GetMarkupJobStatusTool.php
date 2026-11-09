<?php

declare(strict_types=1);

namespace Bitrix\Landing\Integration\AiAssistant\Tool\Site;

use Bitrix\AiAssistant\Exceptions\McpException;
use Bitrix\AiAssistant\Facade\TracedLogger;
use Bitrix\Landing\Integration\AiAssistant\Dto\GetMarkupJobStatusDto;
use Bitrix\Landing\Integration\AiAssistant\Schema\Site\GetMarkupJobStatusSchema;
use Bitrix\Landing\Integration\AiAssistant\Tool\BaseTool;
use Bitrix\Landing\Integration\AiAssistant\UseCase\GetMarkupJobStatusHandler;
use Bitrix\Main\Validation\ValidationService;

class GetMarkupJobStatusTool extends BaseTool
{
	use AiSiteMarkupToolGate;

	public const ACTION_NAME = 'get_markup_job_status';

	public function __construct(
		private readonly GetMarkupJobStatusHandler $handler,
		private readonly GetMarkupJobStatusSchema $schema,
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
		return 'Returns the current markup site generation status by jobId: the current step, intake error codes '
			. 'on failure, and final siteId/pageId/editorUrl with warnings[] {code, token, details} about CRM forms '
			. 'when completed; pass the warnings to the user. The optional wait window (up to 20 seconds) re-reads '
			. 'the state in short intervals and returns early on a final status.';
	}

	protected function getInputSchemaDefinition(): array
	{
		return $this->schema->build();
	}

	protected function validate(object $dto): void
	{
		parent::validate($dto);

		if (!$dto instanceof GetMarkupJobStatusDto)
		{
			throw new \InvalidArgumentException('Invalid markup job status payload.');
		}

		if ($dto->jobId === '')
		{
			throw new \InvalidArgumentException('jobId is required to check the markup job status.');
		}
	}

	protected function executeStructured(int $userId, ...$args): array
	{
		$dto = GetMarkupJobStatusDto::fromArray($args);
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
