<?php

declare(strict_types=1);

namespace Bitrix\Landing\Integration\AiAssistant\Tool\Site;

use Bitrix\AiAssistant\Exceptions\McpException;
use Bitrix\AiAssistant\Facade\TracedLogger;
use Bitrix\Landing\Integration\AiAssistant\Contract\AiSiteChatBindingContract;
use Bitrix\Landing\Integration\AiAssistant\Dto\CreateAiSiteDto;
use Bitrix\Landing\Integration\AiAssistant\Schema\Site\CreateAiSiteSchema;
use Bitrix\Landing\Integration\AiAssistant\Tool\BaseTool;
use Bitrix\Landing\Integration\AiAssistant\UseCase\CreateAiSiteHandler;
use Bitrix\Landing\Rights;
use Bitrix\Main\Validation\ValidationService;

class CreateAiSiteTool extends BaseTool
{
	use AiSiteRuntimeAvailabilityToolGate;

	public const ACTION_NAME = 'create_ai_site';

	public function __construct(
		private readonly CreateAiSiteHandler $handler,
		private readonly CreateAiSiteSchema $schema,
		ValidationService $validationService,
		TracedLogger $tracedLogger,
	)
	{
		parent::__construct($validationService, $tracedLogger);
	}

	public function getDescription(): string
	{
		return 'Starts AI site generation and returns either status=running with a jobId or status=completed with final siteId and pageId.';
	}

	protected function getInputSchemaDefinition(): array
	{
		return $this->schema->build();
	}

	protected function validate(object $dto): void
	{
		parent::validate($dto);

		if (!$dto instanceof CreateAiSiteDto)
		{
			throw new \InvalidArgumentException('Invalid AI site generation payload.');
		}

		if ($dto->briefLines === [])
		{
			throw new \InvalidArgumentException('Brief lines are required to start site generation.');
		}

		if ($dto->bindingId <= 0)
		{
			throw new \InvalidArgumentException(AiSiteChatBindingContract::BINDING_REQUIRED_ERROR);
		}
	}

	protected function executeStructured(int $userId, ...$args): array
	{
		$dto = CreateAiSiteDto::fromArray($args);
		$effectiveUserId = $this->resolveEffectiveUserId($userId);

		try
		{
			$this->validate($dto);

			return $this->runInUserContext($effectiveUserId, function() use ($dto, $effectiveUserId)
			{
				$this->assertPermission(
					Rights::hasAdditionalRight(Rights::ADDITIONAL_RIGHTS['create']),
					'Access denied. You do not have permission to create landing sites.',
				);

				return $this->handler->handle($dto, $effectiveUserId);
			});
		}
		catch (\Throwable $e)
		{
			throw new McpException(message: $e->getMessage(), previous: $e);
		}
	}
}
