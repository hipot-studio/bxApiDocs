<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\Tool;

use Bitrix\AiAssistant\Facade\TracedLogger;
use Bitrix\Main\Validation\ValidationService;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Exception\AccessDeniedException;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Exception\InvalidIdentifierException;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\Dto\AddResultDto;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Exception\DtoValidationException;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Exception\ToolException;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\ResultService;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\SchemaBuilder\ResultSchemaBuilder;

class AddResultTool extends BaseTool
{
	public const ACTION_NAME = 'add_task_result';

	public function __construct(
		private readonly ResultService $resultService,
		ResultSchemaBuilder $schemaBuilder,
		ValidationService $validationService,
		TracedLogger $tracedLogger,
	)
	{
		parent::__construct($schemaBuilder, $validationService, $tracedLogger);
	}

	public function getDescription(): string
	{
		return
			'Adds a formal work summary or result to the task. '
			. 'This is typically used to document the final outcome of a task.'
		;
	}

	protected function execute(int $userId, ...$args): string
	{
		$dto = AddResultDto::fromArray([...$args, 'authorId' => $userId]);

		try
		{
			$this->validate($dto);

			$this->resultService->add($dto, $userId);
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
			return $this->createFailureResponse('The provided task identifier is invalid.');
		}

		return 'Result successfully added.';
	}
}
