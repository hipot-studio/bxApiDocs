<?php

declare(strict_types=1);

namespace Bitrix\Landing\Integration\AiAssistant\Tool\Site;

use Bitrix\AiAssistant\Exceptions\McpException;
use Bitrix\AiAssistant\Facade\TracedLogger;
use Bitrix\Landing\Integration\AiAssistant\Dto\GetMarkupPackageDto;
use Bitrix\Landing\Integration\AiAssistant\Schema\Site\GetMarkupPackageSchema;
use Bitrix\Landing\Integration\AiAssistant\Tool\BaseTool;
use Bitrix\Landing\Integration\AiAssistant\UseCase\GetMarkupPackageHandler;
use Bitrix\Main\Validation\ValidationService;

class GetMarkupPackageTool extends BaseTool
{
	use AiSiteMarkupToolGate;

	public const ACTION_NAME = 'get_markup_package';

	/**
	 * The read serves the edit of a page: it follows the change line.
	 * @return list<string>
	 */
	protected function getMarkupToolGroups(): array
	{
		return [self::MARKUP_TOOL_GROUP_CHANGE];
	}

	public function __construct(
		private readonly GetMarkupPackageHandler $handler,
		private readonly GetMarkupPackageSchema $schema,
		ValidationService $validationService,
		TracedLogger $tracedLogger,
	)
	{
		parent::__construct($validationService, $tracedLogger);
	}

	public function getDescription(): string
	{
		return 'Reads the current markup package version of the custom-markup page of the site the binding is '
			. 'pinned to (the server finds the page by bindingId) in the contract form, file by file: '
			. 'package paths and bx-asset:// tokens, never portal urls. Without path it returns the inventory: '
			. 'files as {path, kind, size, hash, fileId} with the entry first (kind=entry), the image declaration, '
			. 'the page meta and chunkBytes, no contents. With path it returns one part of that file: '
			. '{path, content, offset, nextOffset}, content base64 of at most chunkBytes characters; call again with '
			. 'offset=nextOffset until nextOffset is null. Read only the files to change; unchanged files go back as '
			. '{path, fileId, hash} references in submit_markup_package mode=update. A page without a custom markup '
			. 'block answers with the NOT_MARKUP_PAGE code: the markup tools do not serve such a page.';
	}

	protected function getInputSchemaDefinition(): array
	{
		return $this->schema->build();
	}

	protected function validate(object $dto): void
	{
		parent::validate($dto);

		if (!$dto instanceof GetMarkupPackageDto)
		{
			throw new \InvalidArgumentException('Invalid markup package read payload.');
		}

		if ($dto->bindingId <= 0)
		{
			throw new \InvalidArgumentException(GetMarkupPackageHandler::BINDING_REQUIRED_ERROR);
		}
	}

	protected function executeStructured(int $userId, ...$args): array
	{
		$this->assertAiSiteMarkupModeSelected();

		$dto = GetMarkupPackageDto::fromArray($args);
		$effectiveUserId = $this->resolveEffectiveUserId($userId);

		try
		{
			$this->validate($dto);

			// the edit right belongs to the page the binding resolves to: the handler checks it through
			// the edit target resolver, in this user context
			return $this->runInUserContext(
				$effectiveUserId,
				fn(): array => $this->handler->handle($dto, $effectiveUserId),
			);
		}
		catch (\Throwable $e)
		{
			throw new McpException(message: $e->getMessage(), previous: $e);
		}
	}
}
