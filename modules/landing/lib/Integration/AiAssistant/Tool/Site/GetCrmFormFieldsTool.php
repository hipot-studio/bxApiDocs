<?php

declare(strict_types=1);

namespace Bitrix\Landing\Integration\AiAssistant\Tool\Site;

use Bitrix\AiAssistant\Exceptions\McpException;
use Bitrix\AiAssistant\Facade\TracedLogger;
use Bitrix\Landing\Integration\AiAssistant\Dto\GetCrmFormFieldsDto;
use Bitrix\Landing\Integration\AiAssistant\Schema\Site\GetCrmFormFieldsSchema;
use Bitrix\Landing\Integration\AiAssistant\UseCase\GetCrmFormFieldsHandler;
use Bitrix\Main\Validation\ValidationService;
use InvalidArgumentException;

class GetCrmFormFieldsTool extends AbstractCrmFormTool
{
	public const ACTION_NAME = 'get_crm_form_fields';

	public function __construct(
		private readonly GetCrmFormFieldsHandler $handler,
		private readonly GetCrmFormFieldsSchema $schema,
		ValidationService $validationService,
		TracedLogger $tracedLogger,
	)
	{
		parent::__construct($validationService, $tracedLogger);
	}

	public function getDescription(): string
	{
		return 'Returns a compact snapshot of fields in an existing CRM form, including a fieldsHash for safe updates.';
	}

	protected function getInputSchemaDefinition(): array
	{
		return $this->schema->build();
	}

	protected function executeStructured(int $userId, ...$args): array
	{
		$dto = GetCrmFormFieldsDto::fromArray($args);
		$effectiveUserId = $this->resolveEffectiveUserId($userId);

		try
		{
			$this->validate($dto);
			$this->assertTarget($dto);

			return $this->runInUserContext($effectiveUserId, function() use ($dto)
			{
				$this->assertCrmFormReadPermission();
				if ($dto->pageId !== null && $dto->blockId !== null)
				{
					$this->assertPageEditPermission($dto->pageId);
					$resolvedFormId = $this->resolveCrmFormIdFromBlockTarget($dto->pageId, $dto->blockId);
					$this->assertReadableActiveFormExists($resolvedFormId);
					if ($dto->formId !== null && $dto->formId !== $resolvedFormId)
					{
						throw new InvalidArgumentException(
							'The provided formId does not match the CRM form bound to the selected block.',
						);
					}
				}
				if ($dto->formId !== null)
				{
					$this->assertReadableActiveFormExists($dto->formId);
				}

				return $this->handler->handle($dto);
			});
		}
		catch (\Throwable $e)
		{
			throw new McpException(message: $e->getMessage(), previous: $e);
		}
	}

	private function assertTarget(GetCrmFormFieldsDto $dto): void
	{
		if (($dto->pageId === null) !== ($dto->blockId === null))
		{
			throw new InvalidArgumentException('pageId and blockId must be provided together.');
		}

		if ($dto->formId !== null)
		{
			return;
		}

		if ($dto->pageId !== null && $dto->blockId !== null)
		{
			return;
		}

		throw new InvalidArgumentException('Either formId or pageId + blockId must be provided.');
	}
}
