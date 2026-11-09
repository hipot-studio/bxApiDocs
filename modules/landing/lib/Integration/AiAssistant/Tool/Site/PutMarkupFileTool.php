<?php

declare(strict_types=1);

namespace Bitrix\Landing\Integration\AiAssistant\Tool\Site;

use Bitrix\AiAssistant\Exceptions\McpException;
use Bitrix\AiAssistant\Facade\TracedLogger;
use Bitrix\Landing\Integration\AiAssistant\Contract\AiSiteChatBindingContract;
use Bitrix\Landing\Integration\AiAssistant\Dto\PutMarkupFileDto;
use Bitrix\Landing\Integration\AiAssistant\Schema\Site\PutMarkupFileSchema;
use Bitrix\Landing\Integration\AiAssistant\Service\MarkupRequestAuthorizer;
use Bitrix\Landing\Integration\AiAssistant\Tool\BaseTool;
use Bitrix\Landing\Integration\AiAssistant\UseCase\PutMarkupFileHandler;
use Bitrix\Landing\Rights;
use Bitrix\Main\Result;
use Bitrix\Main\Validation\ValidationService;

class PutMarkupFileTool extends BaseTool
{
	use AiSiteMarkupToolGate;

	public const ACTION_NAME = 'put_markup_file';

	public function __construct(
		private readonly PutMarkupFileHandler $handler,
		private readonly PutMarkupFileSchema $schema,
		ValidationService $validationService,
		TracedLogger $tracedLogger,
	)
	{
		parent::__construct($validationService, $tracedLogger);
	}

	/**
	 * @return list<string>
	 */
	protected function getMarkupToolGroups(): array
	{
		return [self::MARKUP_TOOL_GROUP_CREATE, self::MARKUP_TOOL_GROUP_CHANGE];
	}

	public function getDescription(): string
	{
		return 'Uploads one file of a markup package into the upload buffer. The first call opens the buffer '
			. '(mode plus the target are required) and returns an uploadId; every following call adds the next '
			. 'file to the same uploadId. A repeated path replaces the file, already accepted files are never '
			. 're-uploaded after a break. The package is submitted afterwards with submit_markup_package.';
	}

	protected function getInputSchemaDefinition(): array
	{
		return $this->schema->build();
	}

	protected function validate(object $dto): void
	{
		parent::validate($dto);

		if (!$dto instanceof PutMarkupFileDto)
		{
			throw new \InvalidArgumentException('Invalid markup file payload.');
		}

		// the inventory call names only the uploadId
		if ($dto->path === '' && !$dto->isInventory())
		{
			throw new \InvalidArgumentException('The file path inside the package is required.');
		}

		if ($dto->uploadId === '')
		{
			if ($dto->mode === PutMarkupFileDto::MODE_CREATE)
			{
				if ($dto->bindingId <= 0)
				{
					throw new \InvalidArgumentException(AiSiteChatBindingContract::BINDING_REQUIRED_ERROR);
				}
			}
			elseif ($dto->mode === PutMarkupFileDto::MODE_UPDATE)
			{
				if ($dto->bindingId <= 0)
				{
					throw new \InvalidArgumentException(PutMarkupFileHandler::UPDATE_BINDING_REQUIRED_ERROR);
				}
			}
			else
			{
				throw new \InvalidArgumentException(
					'mode ("create" or "update") with its target is required to open a new upload buffer.',
				);
			}
		}
	}

	protected function executeStructured(int $userId, ...$args): array
	{
		$this->assertAiSiteMarkupModeSelected();

		$dto = PutMarkupFileDto::fromArray($args);
		$effectiveUserId = $this->resolveEffectiveUserId($userId);

		try
		{
			$this->validate($dto);

			return $this->runInUserContext($effectiveUserId, function() use ($dto, $effectiveUserId)
			{
				$this->createRequestAuthorizer()->assertPutTargetPermitted(
					$this->handler->resolveTarget($dto, $effectiveUserId),
					$effectiveUserId,
				);

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

	protected function canEditPage(int $pageId): bool
	{
		return Rights::hasAccessForLanding($pageId, Rights::ACCESS_TYPES['edit']);
	}

	/**
	 * The authorizer the REST intake shares, answering the rights by the seams of the tool. The binding of
	 * an update buffer about to open is resolved by the handler of the same call in this user context: its
	 * refusal by the right carries the message of the resolver, so the tool does not resolve it twice.
	 */
	protected function createRequestAuthorizer(): MarkupRequestAuthorizer
	{
		return new class($this->canCreateLandingSites(...), $this->canEditPage(...)) extends MarkupRequestAuthorizer
		{
			public function __construct(
				private readonly \Closure $canCreate,
				private readonly \Closure $canEdit,
			)
			{
			}

			protected function canCreateLandingSites(): bool
			{
				return ($this->canCreate)();
			}

			protected function canEditPage(int $pageId): bool
			{
				return ($this->canEdit)($pageId);
			}

			protected function resolveEditTarget(int $bindingId, int $userId): Result
			{
				return new Result();
			}
		};
	}
}
