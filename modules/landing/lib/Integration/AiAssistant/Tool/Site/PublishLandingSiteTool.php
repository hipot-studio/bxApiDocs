<?php

declare(strict_types=1);

namespace Bitrix\Landing\Integration\AiAssistant\Tool\Site;

use Bitrix\AiAssistant\Exceptions\McpException;
use Bitrix\AiAssistant\Facade\TracedLogger;
use Bitrix\Landing\Integration\AiAssistant\Dto\PublishLandingSiteDto;
use Bitrix\Landing\Integration\AiAssistant\Schema\Site\PublishLandingSiteSchema;
use Bitrix\Landing\Integration\AiAssistant\Tool\BaseTool;
use Bitrix\Landing\Integration\AiAssistant\UseCase\PublishLandingSiteHandler;
use Bitrix\Landing\Rights;
use Bitrix\Main\Validation\ValidationService;
use InvalidArgumentException;

class PublishLandingSiteTool extends BaseTool
{
	public const ACTION_NAME = 'publish_landing_site';

	public function __construct(
		private readonly PublishLandingSiteHandler $handler,
		private readonly PublishLandingSiteSchema $schema,
		ValidationService $validationService,
		TracedLogger $tracedLogger,
	)
	{
		parent::__construct($validationService, $tracedLogger);
	}

	public function getDescription(): string
	{
		return 'Publishes or unpublishes the specified landing site. This action requires explicit user confirmation.';
	}

	protected function getInputSchemaDefinition(): array
	{
		return $this->schema->build();
	}

	protected function executeStructured(int $userId, ...$args): array
	{
		$dto = PublishLandingSiteDto::fromArray($args);
		$effectiveUserId = $this->resolveEffectiveUserId($userId);

		try
		{
			$this->validate($dto);
			$this->validateAction($dto);

			if (!$dto->confirm)
			{
				throw new InvalidArgumentException('Explicit confirmation is required before changing site publication status. Ask the user to confirm the action and call the tool again with confirm=true.');
			}

			return $this->runInUserContext($effectiveUserId, function() use ($dto, $effectiveUserId)
			{
				$this->assertPermission(
					$this->canChangePublicationStatus((int)$dto->siteId),
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

	private function validateAction(PublishLandingSiteDto $dto): void
	{
		if (!$dto->isValidAction())
		{
			throw new InvalidArgumentException('Action must be either publish or unpublish.');
		}
	}

	protected function canChangePublicationStatus(int $siteId): bool
	{
		return Rights::hasAccessForSite($siteId, Rights::ACCESS_TYPES['public'], true);
	}
}
