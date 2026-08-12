<?php

declare(strict_types=1);

namespace Bitrix\Landing\Integration\AiAssistant\Tool\Site;

use Bitrix\AiAssistant\Exceptions\McpException;
use Bitrix\AiAssistant\Facade\TracedLogger;
use Bitrix\Landing\Integration\AiAssistant\Dto\SetLandingCrmFormDto;
use Bitrix\Landing\Integration\AiAssistant\Schema\Site\SetLandingCrmFormSchema;
use Bitrix\Landing\Integration\AiAssistant\UseCase\SetLandingCrmFormHandler;
use Bitrix\Main\Validation\ValidationService;

class SetLandingCrmFormTool extends AbstractCrmFormTool
{
	public const ACTION_NAME = 'set_landing_crm_form';

	public function __construct(
		private readonly SetLandingCrmFormHandler $handler,
		private readonly SetLandingCrmFormSchema $schema,
		ValidationService $validationService,
		TracedLogger $tracedLogger,
	)
	{
		parent::__construct($validationService, $tracedLogger);
	}

	public function getDescription(): string
	{
		return 'Sets the selected CRM form into an existing CRM-form block on a landing page.';
	}

	protected function getInputSchemaDefinition(): array
	{
		return $this->schema->build();
	}

	protected function executeStructured(int $userId, ...$args): array
	{
		$dto = SetLandingCrmFormDto::fromArray($args);
		$effectiveUserId = $this->resolveEffectiveUserId($userId);

		try
		{
			$this->validate($dto);

			return $this->runInUserContext($effectiveUserId, function() use ($dto, $effectiveUserId)
			{
				$this->assertCrmFormReadPermission();
				$this->assertPageEditPermission($dto->pageId);
				$this->assertBlockBelongsToPageAndHasCrmForm($dto->pageId, $dto->blockId);
				$this->assertReadableActiveFormExists($dto->formId);

				return $this->handler->handle($dto, $effectiveUserId);
			});
		}
		catch (\Throwable $e)
		{
			throw new McpException(message: $e->getMessage(), previous: $e);
		}
	}
}
