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
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\Dto\CheckList\DeleteCheckListItemDto;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\SchemaBuilder\CheckListSchemaBuilder;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\Tool\BaseTool;

class DeleteCheckListItemTool extends BaseTool
{
	public const ACTION_NAME = 'delete_check_list_item';

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
		return 'Deletes an entire checklist item from a task. This action is irreversible.';
	}

	protected function execute(int $userId, ...$args): string
	{
		$dto = DeleteCheckListItemDto::fromArray($args);

		try
		{
			$this->validate($dto);

			$this->checkListService->delete($dto, $userId);
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
			return $this->createFailureResponse('The provided checklist item identifier is invalid.');
		}
		catch (CheckListNotFoundException)
		{
			return $this->createFailureResponse('The provided checklist item is not found.');
		}

		return 'Checklist successfully deleted.';
	}
}
