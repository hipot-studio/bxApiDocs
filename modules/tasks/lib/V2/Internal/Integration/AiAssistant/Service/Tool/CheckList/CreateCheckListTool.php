<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\Tool\CheckList;

use Bitrix\AiAssistant\Facade\TracedLogger;
use Bitrix\Main\Validation\ValidationService;
use Bitrix\Tasks\Exception;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Exception\AccessDeniedException;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Exception\DtoValidationException;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Exception\InvalidIdentifierException;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\CheckListService;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\Dto\CheckList\CreateCheckListDto;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\SchemaBuilder\CheckListSchemaBuilder;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\Tool\BaseTool;

class CreateCheckListTool extends BaseTool
{
	public const ACTION_NAME = 'create_check_list';

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
		return 'Creates a new checklist inside a task.';
	}

	protected function execute(int $userId, ...$args): string
	{
		$dto = CreateCheckListDto::fromArray($args);

		try
		{
			$this->validate($dto);

			$this->checkListService->add($dto, $userId);
		}
		catch (AccessDeniedException)
		{
			return $this->createFailureResponse('Access denied.');
		}
		catch (InvalidIdentifierException)
		{
			return $this->createFailureResponse('The provided task identifier is invalid.');
		}
		catch (DtoValidationException|Exception $e)
		{
			return $this->createFailureResponse($e->getMessage());
		}

		return 'Checklist successfully created.';
	}
}
