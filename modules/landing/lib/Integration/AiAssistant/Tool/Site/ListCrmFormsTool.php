<?php

declare(strict_types=1);

namespace Bitrix\Landing\Integration\AiAssistant\Tool\Site;

use Bitrix\AiAssistant\Exceptions\McpException;
use Bitrix\AiAssistant\Facade\TracedLogger;
use Bitrix\Landing\Integration\AiAssistant\Dto\ListCrmFormsDto;
use Bitrix\Landing\Integration\AiAssistant\Schema\Site\ListCrmFormsSchema;
use Bitrix\Landing\Integration\AiAssistant\UseCase\ListCrmFormsHandler;
use Bitrix\Main\Validation\ValidationService;

class ListCrmFormsTool extends AbstractCrmFormTool
{
	public const ACTION_NAME = 'list_crm_forms';

	public function __construct(
		private readonly ListCrmFormsHandler $handler,
		private readonly ListCrmFormsSchema $schema,
		ValidationService $validationService,
		TracedLogger $tracedLogger,
	)
	{
		parent::__construct($validationService, $tracedLogger);
	}

	public function getDescription(): string
	{
		return 'Returns active CRM forms available for selecting in landing CRM-form blocks.';
	}

	protected function getInputSchemaDefinition(): array
	{
		return $this->schema->build();
	}

	protected function executeStructured(int $userId, ...$args): array
	{
		$dto = ListCrmFormsDto::fromArray($args);
		$effectiveUserId = $this->resolveEffectiveUserId($userId);

		try
		{
			$this->validate($dto);

			return $this->runInUserContext($effectiveUserId, function() use ($dto)
			{
				$this->assertCrmFormReadPermission();

				return $this->handler->handle($dto);
			});
		}
		catch (\Throwable $e)
		{
			throw new McpException(message: $e->getMessage(), previous: $e);
		}
	}
}
