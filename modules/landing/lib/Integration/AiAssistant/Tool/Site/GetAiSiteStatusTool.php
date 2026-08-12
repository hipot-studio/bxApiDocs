<?php

declare(strict_types=1);

namespace Bitrix\Landing\Integration\AiAssistant\Tool\Site;

use Bitrix\AiAssistant\Exceptions\McpException;
use Bitrix\AiAssistant\Facade\TracedLogger;
use Bitrix\Landing\Integration\AiAssistant\Dto\GetAiSiteStatusDto;
use Bitrix\Landing\Integration\AiAssistant\Schema\Site\GetAiSiteStatusSchema;
use Bitrix\Landing\Integration\AiAssistant\Tool\BaseTool;
use Bitrix\Landing\Integration\AiAssistant\UseCase\GetAiSiteStatusHandler;
use Bitrix\Main\Validation\ValidationService;

class GetAiSiteStatusTool extends BaseTool
{
	use AiSiteRuntimeAvailabilityToolGate;

	public const ACTION_NAME = 'get_ai_site_status';

	public function __construct(
		private readonly GetAiSiteStatusHandler $handler,
		private readonly GetAiSiteStatusSchema $schema,
		ValidationService $validationService,
		TracedLogger $tracedLogger,
	)
	{
		parent::__construct($validationService, $tracedLogger);
	}

	public function getDescription(): string
	{
		return 'Optionally waits for the requested number of seconds and then returns the current AI site generation status with final siteId and pageId when the job is completed.';
	}

	protected function getInputSchemaDefinition(): array
	{
		return $this->schema->build();
	}

	protected function executeStructured(int $userId, ...$args): array
	{
		$dto = GetAiSiteStatusDto::fromArray($args);
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
