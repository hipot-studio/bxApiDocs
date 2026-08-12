<?php

declare(strict_types=1);

namespace Bitrix\Landing\Integration\AiAssistant\Tool\Site;

use Bitrix\AiAssistant\Exceptions\McpException;
use Bitrix\AiAssistant\Facade\TracedLogger;
use Bitrix\Landing\Integration\AiAssistant\Dto\SearchDeletedLandingSitesDto;
use Bitrix\Landing\Integration\AiAssistant\Schema\Site\SearchDeletedLandingSitesSchema;
use Bitrix\Landing\Integration\AiAssistant\Tool\BaseTool;
use Bitrix\Landing\Integration\AiAssistant\UseCase\SearchDeletedLandingSitesHandler;
use Bitrix\Main\Validation\ValidationService;

class SearchDeletedLandingSitesTool extends BaseTool
{
	public const ACTION_NAME = 'search_deleted_landing_sites';

	public function __construct(
		private readonly SearchDeletedLandingSitesHandler $handler,
		private readonly SearchDeletedLandingSitesSchema $schema,
		ValidationService $validationService,
		TracedLogger $tracedLogger,
	)
	{
		parent::__construct($validationService, $tracedLogger);
	}

	public function getDescription(): string
	{
		return 'Finds deleted landing sites by title or site code and returns a short list of matching sites.';
	}

	protected function getInputSchemaDefinition(): array
	{
		return $this->schema->build();
	}

	protected function executeStructured(int $userId, ...$args): array
	{
		$dto = SearchDeletedLandingSitesDto::fromArray($args);

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
