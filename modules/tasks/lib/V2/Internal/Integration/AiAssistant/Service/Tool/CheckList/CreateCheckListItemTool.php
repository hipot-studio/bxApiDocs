<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\Tool\CheckList;

use Bitrix\AiAssistant\Facade\TracedLogger;
use Bitrix\Main\Validation\ValidationService;
use Bitrix\Tasks\V2\Internal\Exception\CheckList\CheckListNotFoundException;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Exception\AccessDeniedException;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Exception\DtoValidationException;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Exception\InvalidIdentifierException;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\CheckListService;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\Dto\CheckList\CreateCheckListItemDto;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\SchemaBuilder\CheckListSchemaBuilder;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\Tool\BaseTool;

class CreateCheckListItemTool extends BaseTool
{
	public const ACTION_NAME = 'create_check_list_item';

	public function __construct(
		private readonly CheckListService $checkListService,
		CheckListSchemaBuilder $schemaBuilder,
		ValidationService $validationService,
		TracedLogger $tracedLogger,
	)
	{
		parent::__construct($schemaBuilder, $validationService, $tracedLogger);
	}

	public function getDescription(): string
	{
		return 'Adds a new item to a checklist.';
	}

	protected function execute(int $userId, ...$args): string
	{
		$dto = CreateCheckListItemDto::fromArray($args);

		try
		{
			$this->validate($dto);

			$this->checkListService->addItem($dto, $userId);
		}
		catch (DtoValidationException $e)
		{
			return $this->createFailureResponse($e->getMessage());
		}
		catch (AccessDeniedException)
		{
			return $this->createFailureResponse('Access denied.');
		}
		catch (InvalidIdentifierException)
		{
			return $this->createFailureResponse('The provided checklist identifier is invalid.');
		}
		catch (CheckListNotFoundException)
		{
			return $this->createFailureResponse('The provided checklist is not found.');
		}

		return 'Checklist item successfully added.';
	}
}
