<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\Tool;

use Bitrix\AiAssistant\Exceptions\McpException;
use Bitrix\AiAssistant\Facade\TracedLogger;
use Bitrix\Main\Validation\ValidationService;
use Bitrix\Tasks\V2\Internal\Access\Service\TaskAccessService;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Exception\DtoValidationException;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Exception\InvalidIdentifierException;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Provider\TaskHistoryProvider;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\Dto\GetTaskHistoryDto;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\SchemaBuilder\HistorySchemaBuilder;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\Tool\Task\SearchTasksTool;
use Bitrix\Tasks\V2\Internal\Integration\Intranet\Service\ToolService;

class GetTaskHistoryTool extends BaseTool
{
	public const ACTION_NAME = 'get_task_history';

	public function __construct(
		private readonly TaskHistoryProvider $historyProvider,
		private readonly TaskAccessService $taskAccessService,
		ToolService $toolService,
		HistorySchemaBuilder $schemaBuilder,
		ValidationService $validationService,
		TracedLogger $tracedLogger,
	)
	{
		parent::__construct($toolService, $schemaBuilder, $validationService, $tracedLogger);
	}

	public function getDescription(): string
	{
		return
			'Retrieves task change history entries — field-level changes such as status, deadline, '
			. 'responsible, accomplices, auditors, title, description, priority, and stage transitions. '
			. 'Use this tool to inspect what changed in a task over time, who made each change, and the '
			. 'previous vs new value. Records are returned newest first; paginate with limit/offset to '
			. 'walk further into the past. '
			. 'For the task\'s current state use get_task_by_id; for chat discussion use get_chat_messages; '
			. 'to find tasks across the workspace use ' . SearchTasksTool::ACTION_NAME
		;
	}

	protected function executeStructured(int $userId, ...$args): array
	{
		$dto = GetTaskHistoryDto::fromArray($args);

		try
		{
			$this->validate($dto);
		}
		catch (DtoValidationException $e)
		{
			throw new McpException($e->getMessage(), previous: $e);
		}

		if (!$this->taskAccessService->canRead($userId, $dto->taskId))
		{
			throw new McpException('Access denied');
		}

		try
		{
			return $this->historyProvider->get($dto, $userId);
		}
		catch (InvalidIdentifierException)
		{
			throw new McpException('The provided task identifier is invalid');
		}
	}
}
