<?php

declare(strict_types=1);

namespace Bitrix\Landing\Integration\AiAssistant\Tool\Site;

use Bitrix\AiAssistant\Exceptions\McpException;
use Bitrix\AiAssistant\Facade\TracedLogger;
use Bitrix\Landing\Integration\AiAssistant\Dto\RestoreLandingSiteDto;
use Bitrix\Landing\Integration\AiAssistant\Schema\Site\RestoreLandingSiteSchema;
use Bitrix\Landing\Integration\AiAssistant\Tool\BaseTool;
use Bitrix\Landing\Integration\AiAssistant\UseCase\RestoreLandingSiteHandler;
use Bitrix\Landing\Rights;
use Bitrix\Main\Validation\ValidationService;
use InvalidArgumentException;

class RestoreLandingSiteTool extends BaseTool
{
	public const ACTION_NAME = 'restore_landing_site';

	public function __construct(
		private readonly RestoreLandingSiteHandler $handler,
		private readonly RestoreLandingSiteSchema $schema,
		ValidationService $validationService,
		TracedLogger $tracedLogger,
	)
	{
		parent::__construct($validationService, $tracedLogger);
	}

	public function getDescription(): string
	{
		return 'Restores the specified landing site from the recycle bin. This action requires explicit user confirmation.';
	}

	protected function getInputSchemaDefinition(): array
	{
		return $this->schema->build();
	}

	protected function executeStructured(int $userId, ...$args): array
	{
		$dto = RestoreLandingSiteDto::fromArray($args);
		$effectiveUserId = $this->resolveEffectiveUserId($userId);

		try
		{
			$this->validate($dto);

			if ($dto->confirm !== true)
			{
				throw new InvalidArgumentException(
					'Explicit confirmation is required before restoring the site. '
					. 'Ask the user to confirm the restoration and call the tool again with confirm=true.',
				);
			}

			return $this->runInUserContext($effectiveUserId, function() use ($dto, $effectiveUserId)
			{
				$this->assertPermission(
					in_array(Rights::ACCESS_TYPES['delete'], Rights::getOperationsForSite($dto->siteId), true),
					'Site not found or access denied.',
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
