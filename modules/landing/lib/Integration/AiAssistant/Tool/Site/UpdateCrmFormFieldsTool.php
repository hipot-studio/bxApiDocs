<?php

declare(strict_types=1);

namespace Bitrix\Landing\Integration\AiAssistant\Tool\Site;

use Bitrix\AiAssistant\Exceptions\McpException;
use Bitrix\AiAssistant\Facade\TracedLogger;
use Bitrix\Landing\Integration\AiAssistant\Dto\UpdateCrmFormFieldsDto;
use Bitrix\Landing\Integration\AiAssistant\Schema\Site\UpdateCrmFormFieldsSchema;
use Bitrix\Landing\Integration\AiAssistant\UseCase\UpdateCrmFormFieldsHandler;
use Bitrix\Main\Validation\ValidationService;
use InvalidArgumentException;

class UpdateCrmFormFieldsTool extends AbstractCrmFormTool
{
	public const ACTION_NAME = 'update_crm_form_fields';

	public function __construct(
		private readonly UpdateCrmFormFieldsHandler $handler,
		private readonly UpdateCrmFormFieldsSchema $schema,
		ValidationService $validationService,
		TracedLogger $tracedLogger,
	)
	{
		parent::__construct($validationService, $tracedLogger);
	}

	public function getDescription(): string
	{
		return 'Updates fields of an existing CRM form using a safe list of add, remove, update, and move operations.';
	}

	protected function getInputSchemaDefinition(): array
	{
		return $this->schema->build();
	}

	protected function executeStructured(int $userId, ...$args): array
	{
		$dto = UpdateCrmFormFieldsDto::fromArray($args);
		$effectiveUserId = $this->resolveEffectiveUserId($userId);

		try
		{
			$this->validate($dto);
			$this->assertBlockTarget($dto);
			$this->assertOperations($dto);

			return $this->runInUserContext($effectiveUserId, function() use ($dto, $effectiveUserId)
			{
				$this->assertCrmFormWritePermission();
				$this->assertReadableActiveFormExists((int)$dto->formId);
				if ($dto->pageId !== null && $dto->blockId !== null)
				{
					$this->assertPageEditPermission($dto->pageId);
					$resolvedFormId = $this->resolveCrmFormIdFromBlockTarget($dto->pageId, $dto->blockId);
					$this->assertReadableActiveFormExists($resolvedFormId);
					if ((int)$dto->formId !== $resolvedFormId)
					{
						throw new InvalidArgumentException(
							'The provided formId does not match the CRM form bound to the selected block.',
						);
					}
				}

				return $this->handler->handle($dto, $effectiveUserId);
			});
		}
		catch (\Throwable $e)
		{
			throw new McpException(message: $e->getMessage(), previous: $e);
		}
	}

	private function assertBlockTarget(UpdateCrmFormFieldsDto $dto): void
	{
		if (($dto->pageId === null) !== ($dto->blockId === null))
		{
			throw new InvalidArgumentException('pageId and blockId must be provided together.');
		}
	}

	private function assertOperations(UpdateCrmFormFieldsDto $dto): void
	{
		if ($dto->operations === [])
		{
			throw new InvalidArgumentException('operations must contain at least one CRM form field operation.');
		}
	}
}
