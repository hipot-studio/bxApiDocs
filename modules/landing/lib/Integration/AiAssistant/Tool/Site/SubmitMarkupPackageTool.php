<?php

declare(strict_types=1);

namespace Bitrix\Landing\Integration\AiAssistant\Tool\Site;

use Bitrix\AiAssistant\Exceptions\McpException;
use Bitrix\AiAssistant\Facade\TracedLogger;
use Bitrix\Landing\Integration\AiAssistant\Contract\AiSiteChatBindingContract;
use Bitrix\Landing\Integration\AiAssistant\Dto\SubmitMarkupPackageDto;
use Bitrix\Landing\Integration\AiAssistant\Schema\Site\SubmitMarkupPackageSchema;
use Bitrix\Landing\Integration\AiAssistant\Tool\BaseTool;
use Bitrix\Landing\Integration\AiAssistant\UseCase\SubmitMarkupPackageHandler;
use Bitrix\Landing\Rights;
use Bitrix\Main\Validation\ValidationService;

class SubmitMarkupPackageTool extends BaseTool
{
	use AiSiteMarkupToolGate;

	public const ACTION_NAME = 'submit_markup_package';

	/**
	 * @return list<string>
	 */
	protected function getMarkupToolGroups(): array
	{
		return [self::MARKUP_TOOL_GROUP_CREATE, self::MARKUP_TOOL_GROUP_CHANGE];
	}

	public function __construct(
		private readonly SubmitMarkupPackageHandler $handler,
		private readonly SubmitMarkupPackageSchema $schema,
		ValidationService $validationService,
		TracedLogger $tracedLogger,
	)
	{
		parent::__construct($validationService, $tracedLogger);
	}

	public function getDescription(): string
	{
		return 'Submits a complete markup package (entry HTML, asset files, image declaration, page meta): '
			. 'mode=create starts markup site creation by bindingId, mode=update applies the package as the '
			. 'new version of the custom-markup page of the site the binding is pinned to (the server finds '
			. 'the page by bindingId; one active submission per page). '
			. 'Returns status with a jobId, final siteId/pageId/editorUrl with warnings[] {code, token, details} '
			. 'about CRM forms and the images summary when completed, or status=failed with machine-readable '
			. 'intake error codes.';
	}

	protected function getInputSchemaDefinition(): array
	{
		return $this->schema->build();
	}

	protected function validate(object $dto): void
	{
		parent::validate($dto);

		if (!$dto instanceof SubmitMarkupPackageDto)
		{
			throw new \InvalidArgumentException('Invalid markup package submission payload.');
		}

		if ($dto->mode === SubmitMarkupPackageDto::MODE_CREATE)
		{
			if ($dto->bindingId <= 0)
			{
				throw new \InvalidArgumentException(AiSiteChatBindingContract::BINDING_REQUIRED_ERROR);
			}
		}
		elseif ($dto->mode === SubmitMarkupPackageDto::MODE_UPDATE)
		{
			if ($dto->bindingId <= 0)
			{
				throw new \InvalidArgumentException(SubmitMarkupPackageHandler::UPDATE_BINDING_REQUIRED_ERROR);
			}
		}
		else
		{
			throw new \InvalidArgumentException('Only modes "create" and "update" are supported for markup package submission.');
		}

		if ($dto->entryPath === '')
		{
			throw new \InvalidArgumentException('The package entry document with path and content is required.');
		}

		$entryHasReference = $dto->entryFileId > 0 && $dto->entryHash !== '';
		if ($dto->entryContent === '' && !$entryHasReference && $dto->uploadId === '')
		{
			throw new \InvalidArgumentException(
				'The package entry document is required: pass its content inline, reference a stored file, or submit with the uploadId that holds it.',
			);
		}
	}

	protected function executeStructured(int $userId, ...$args): array
	{
		$this->assertAiSiteMarkupModeSelected();

		$dto = SubmitMarkupPackageDto::fromArray($args);
		$effectiveUserId = $this->resolveEffectiveUserId($userId);

		try
		{
			$this->validate($dto);

			return $this->runInUserContext($effectiveUserId, function() use ($dto, $effectiveUserId)
			{
				// the edit right belongs to the page the binding resolves to: the handler checks it
				// through the edit target resolver, in this user context
				if ($dto->mode === SubmitMarkupPackageDto::MODE_CREATE)
				{
					$this->assertPermission(
						$this->canCreateLandingSites(),
						'Access denied. You do not have permission to create landing sites.',
					);
				}

				return $this->handler->handle($dto, $effectiveUserId);
			});
		}
		catch (\Throwable $e)
		{
			throw new McpException(message: $e->getMessage(), previous: $e);
		}
	}

	protected function canCreateLandingSites(): bool
	{
		return Rights::hasAdditionalRight(Rights::ADDITIONAL_RIGHTS['create']);
	}
}
