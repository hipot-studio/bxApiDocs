<?php

declare(strict_types=1);

namespace Bitrix\Landing\Integration\AiAssistant\Tool\Site;

use Bitrix\AiAssistant\Exceptions\McpException;
use Bitrix\AiAssistant\Facade\TracedLogger;
use Bitrix\Landing\Integration\AiAssistant\Dto\CreateLandingSiteDto;
use Bitrix\Landing\Integration\AiAssistant\Schema\Site\CreateLandingSiteSchema;
use Bitrix\Landing\Integration\AiAssistant\Tool\BaseTool;
use Bitrix\Landing\Integration\AiAssistant\UseCase\CreateLandingSiteHandler;
use Bitrix\Landing\Rights;
use Bitrix\Main\Validation\ValidationService;

class CreateLandingSiteTool extends BaseTool
{
	public const ACTION_NAME = 'create_landing_site';

	public function __construct(
		private readonly CreateLandingSiteHandler $handler,
		private readonly CreateLandingSiteSchema $schema,
		ValidationService $validationService,
		TracedLogger $tracedLogger,
	)
	{
		parent::__construct($validationService, $tracedLogger);
	}

	public function getDescription(): string
	{
		return 'Creates a landing site with a single initial page and optionally publishes it.';
	}

	protected function getInputSchemaDefinition(): array
	{
		return $this->schema->build();
	}

	protected function executeStructured(int $userId, ...$args): array
	{
		$dto = CreateLandingSiteDto::fromArray($args);
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
