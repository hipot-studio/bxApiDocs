<?php

declare(strict_types=1);

namespace Bitrix\Landing\Integration\AiAssistant\Tool\Site;

use Bitrix\AiAssistant\Exceptions\McpException;
use Bitrix\AiAssistant\Facade\TracedLogger;
use Bitrix\Landing\Integration\AiAssistant\Dto\DeleteLandingSiteDto;
use Bitrix\Landing\Integration\AiAssistant\Schema\Site\DeleteLandingSiteSchema;
use Bitrix\Landing\Integration\AiAssistant\Tool\BaseTool;
use Bitrix\Landing\Integration\AiAssistant\UseCase\DeleteLandingSiteHandler;
use Bitrix\Landing\Rights;
use Bitrix\Main\Validation\ValidationService;
use InvalidArgumentException;

class DeleteLandingSiteTool extends BaseTool
{
	public const ACTION_NAME = 'delete_landing_site';

	public function __construct(
		private readonly DeleteLandingSiteHandler $handler,
		private readonly DeleteLandingSiteSchema $schema,
		ValidationService $validationService,
		TracedLogger $tracedLogger,
	)
	{
		parent::__construct($validationService, $tracedLogger);
	}

	public function getDescription(): string
	{
		return 'Moves the specified landing site to the recycle bin. This action requires explicit user confirmation.';
	}

	protected function getInputSchemaDefinition(): array
	{
		return $this->schema->build();
	}

	protected function executeStructured(int $userId, ...$args): array
	{
		$dto = DeleteLandingSiteDto::fromArray($args);
		$effectiveUserId = $this->resolveEffectiveUserId($userId);

		try
		{
			$this->validate($dto);

			if (!$dto->confirm)
			{
				throw new InvalidArgumentException('Explicit confirmation is required before deleting the site. Ask the user to confirm the deletion and call the tool again with confirm=true.');
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
