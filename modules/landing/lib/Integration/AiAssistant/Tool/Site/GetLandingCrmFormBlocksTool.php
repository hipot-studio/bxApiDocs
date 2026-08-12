<?php

declare(strict_types=1);

namespace Bitrix\Landing\Integration\AiAssistant\Tool\Site;

use Bitrix\AiAssistant\Exceptions\McpException;
use Bitrix\AiAssistant\Facade\TracedLogger;
use Bitrix\Landing\Integration\AiAssistant\Dto\GetLandingCrmFormBlocksDto;
use Bitrix\Landing\Integration\AiAssistant\Schema\Site\GetLandingCrmFormBlocksSchema;
use Bitrix\Landing\Integration\AiAssistant\UseCase\GetLandingCrmFormBlocksHandler;
use Bitrix\Main\Validation\ValidationService;

class GetLandingCrmFormBlocksTool extends AbstractCrmFormTool
{
	public const ACTION_NAME = 'get_landing_crm_form_blocks';

	public function __construct(
		private readonly GetLandingCrmFormBlocksHandler $handler,
		private readonly GetLandingCrmFormBlocksSchema $schema,
		ValidationService $validationService,
		TracedLogger $tracedLogger,
	)
	{
		parent::__construct($validationService, $tracedLogger);
	}

	public function getDescription(): string
	{
		return 'Returns CRM-form blocks on the specified landing page with their current CRM form bindings.';
	}

	protected function getInputSchemaDefinition(): array
	{
		return $this->schema->build();
	}

	protected function executeStructured(int $userId, ...$args): array
	{
		$dto = GetLandingCrmFormBlocksDto::fromArray($args);
		$effectiveUserId = $this->resolveEffectiveUserId($userId);

		try
		{
			$this->validate($dto);

			return $this->runInUserContext($effectiveUserId, function() use ($dto)
			{
				$this->assertCrmFormReadPermission();
				$this->assertPageEditPermission($dto->pageId);

				return $this->handler->handle($dto);
			});
		}
		catch (\Throwable $e)
		{
			throw new McpException(message: $e->getMessage(), previous: $e);
		}
	}
}
