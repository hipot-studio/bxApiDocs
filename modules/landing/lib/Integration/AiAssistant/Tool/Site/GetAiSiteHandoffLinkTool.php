<?php

declare(strict_types=1);

namespace Bitrix\Landing\Integration\AiAssistant\Tool\Site;

use Bitrix\AiAssistant\Exceptions\McpException;
use Bitrix\AiAssistant\Facade\TracedLogger;
use Bitrix\Landing\Integration\AiAssistant\Dto\GetAiSiteHandoffLinkDto;
use Bitrix\Landing\Integration\AiAssistant\Schema\Site\GetAiSiteHandoffLinkSchema;
use Bitrix\Landing\Integration\AiAssistant\Tool\BaseTool;
use Bitrix\Landing\Integration\AiAssistant\UseCase\GetAiSiteHandoffLinkHandler;
use Bitrix\Main\Validation\ValidationService;

class GetAiSiteHandoffLinkTool extends BaseTool
{
	use AiSiteRuntimeAvailabilityToolGate;

	public const ACTION_NAME = 'get_ai_site_handoff_link';

	/** The request reaches the chat by the token only and is not written to the journal. */
	protected const LOG_MASKED_ARGUMENTS = ['request'];

	public function __construct(
		private readonly GetAiSiteHandoffLinkHandler $handler,
		private readonly GetAiSiteHandoffLinkSchema $schema,
		ValidationService $validationService,
		TracedLogger $tracedLogger,
	)
	{
		parent::__construct($validationService, $tracedLogger);
	}

	public function getDescription(): string
	{
		return 'Returns a one-time link to /sites/ai/ (target "sites_ai": a new AI site in a new chat) or to the'
			. ' editor of an AI site (target "editor", by siteId) that carries the request of the user and sends'
			. ' it to the chat when the user opens the link. The link is personal and valid for 1 hour.';
	}

	protected function getInputSchemaDefinition(): array
	{
		return $this->schema->build();
	}

	protected function executeStructured(int $userId, ...$args): array
	{
		$dto = GetAiSiteHandoffLinkDto::fromArray($args);

		try
		{
			$this->validate($dto);

			return $this->runInUserContext($userId, fn() => $this->handler->handle($dto, $userId));
		}
		catch (\Throwable $e)
		{
			throw new McpException(message: $e->getMessage(), previous: $e);
		}
	}
}
