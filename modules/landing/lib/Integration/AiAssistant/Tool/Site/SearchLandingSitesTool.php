<?php

declare(strict_types=1);

namespace Bitrix\Landing\Integration\AiAssistant\Tool\Site;

use Bitrix\AiAssistant\Exceptions\McpException;
use Bitrix\AiAssistant\Facade\TracedLogger;
use Bitrix\Landing\Integration\AiAssistant\Dto\SearchLandingSitesDto;
use Bitrix\Landing\Integration\AiAssistant\Schema\Site\SearchLandingSitesSchema;
use Bitrix\Landing\Integration\AiAssistant\Tool\BaseTool;
use Bitrix\Landing\Integration\AiAssistant\UseCase\SearchLandingSitesHandler;
use Bitrix\Main\Validation\ValidationService;

class SearchLandingSitesTool extends BaseTool
{
	public const ACTION_NAME = 'search_landing_sites';

	public function __construct(
		private readonly SearchLandingSitesHandler $handler,
		private readonly SearchLandingSitesSchema $schema,
		ValidationService $validationService,
		TracedLogger $tracedLogger,
	)
	{
		parent::__construct($validationService, $tracedLogger);
	}

	public function getDescription(): string
	{
		return 'Finds landing sites by title or site code and returns a short list of matching sites.';
	}

	protected function getInputSchemaDefinition(): array
	{
		return $this->schema->build();
	}

	protected function executeStructured(int $userId, ...$args): array
	{
		$dto = SearchLandingSitesDto::fromArray($args);

		try
		{
			$this->validate($dto);

			return $this->runInUserContext($userId, fn() => $this->handler->handle($dto));
		}
		catch (\Throwable $e)
		{
			throw new McpException(message: $e->getMessage(), previous: $e);
		}
	}
}
