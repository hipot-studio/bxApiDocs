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
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\Dto\CheckList\UpdateCheckListDto;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\SchemaBuilder\CheckListSchemaBuilder;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\Tool\BaseTool;

class UpdateCheckListTool extends BaseTool
{
	public const ACTION_NAME = 'update_check_list';

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
		return 'Updates the name or order of an existing checklist or checklist item.';
	}

	protected function execute(int $userId, ...$args): string
	{
		$dto = UpdateCheckListDto::fromArray($args);

		try
		{
			$this->validate($dto);

			$this->checkListService->update($dto, $userId);
		}
		catch (DtoValidationException $e)
		{
			return $this->createFailureResponse($e->getMessage());
		}
		catch (AccessDeniedException)
		{
			return $this->createFailureResponse('Access denied.');
		}
		catch (CheckListNotFoundException)
		{
			return $this->createFailureResponse('The provided checklist is not found.');
		}
		catch (InvalidIdentifierException)
		{
			return $this->createFailureResponse('The provided checklist identifier is invalid.');
		}

		return 'Checklist successfully updated.';
	}

	protected function validate(object $dto): void
	{
		parent::validate($dto);

		if ($dto->isEmpty())
		{
			throw new DtoValidationException('At least one field to update must be provided.');
		}
	}
}
