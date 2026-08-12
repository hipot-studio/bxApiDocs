<?php

declare(strict_types=1);

namespace Bitrix\Landing\Integration\AiAssistant\Tool\Site;

use Bitrix\AiAssistant\Exceptions\McpException;
use Bitrix\AiAssistant\Facade\TracedLogger;
use Bitrix\Landing\Integration\AiAssistant\Dto\ListCrmFormAvailableFieldsDto;
use Bitrix\Landing\Integration\AiAssistant\Schema\Site\ListCrmFormAvailableFieldsSchema;
use Bitrix\Landing\Integration\AiAssistant\UseCase\ListCrmFormAvailableFieldsHandler;
use Bitrix\Main\Validation\ValidationService;

class ListCrmFormAvailableFieldsTool extends AbstractCrmFormTool
{
	public const ACTION_NAME = 'list_crm_form_available_fields';

	public function __construct(
		private readonly ListCrmFormAvailableFieldsHandler $handler,
		private readonly ListCrmFormAvailableFieldsSchema $schema,
		ValidationService $validationService,
		TracedLogger $tracedLogger,
	)
	{
		parent::__construct($validationService, $tracedLogger);
	}

	public function getDescription(): string
	{
		return 'Lists CRM fields that can be safely added to an existing CRM form.';
	}

	protected function getInputSchemaDefinition(): array
	{
		return $this->schema->build();
	}

	protected function executeStructured(int $userId, ...$args): array
	{
		$dto = ListCrmFormAvailableFieldsDto::fromArray($args);
		$effectiveUserId = $this->resolveEffectiveUserId($userId);

		try
		{
			$this->validate($dto);

			return $this->runInUserContext($effectiveUserId, function() use ($dto)
			{
				$this->assertCrmFormReadPermission();
				$this->assertReadableActiveFormExists((int)$dto->formId);

				return $this->handler->handle($dto);
			});
		}
		catch (\Throwable $e)
		{
			throw new McpException(message: $e->getMessage(), previous: $e);
		}
	}
}
