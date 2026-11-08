<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\Tool;

use Bitrix\AiAssistant\Exceptions\McpException;
use Bitrix\AiAssistant\Facade\TracedLogger;
use Bitrix\Main\Loader;
use Bitrix\Main\Validation\ValidationService;
use Bitrix\Tasks\V2\Internal\Access\Service\TaskAccessService;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Exception\DtoValidationException;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Exception\InvalidIdentifierException;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Provider\ChatProvider;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\Dto\GetChatMessagesDto;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\SchemaBuilder\ChatSchemaBuilder;
use Bitrix\Tasks\V2\Internal\Integration\Intranet\Service\ToolService;

class GetChatMessagesTool extends BaseTool
{
	public const ACTION_NAME = 'get_chat_messages';

	public function __construct(
		private readonly ChatProvider $chatProvider,
		private readonly TaskAccessService $taskAccessService,
		ToolService $toolService,
		ChatSchemaBuilder $schemaBuilder,
		ValidationService $validationService,
		TracedLogger $tracedLogger,
	)
	{
		parent::__construct($toolService, $schemaBuilder, $validationService, $tracedLogger);
	}

	public function getDescription(): string
	{
		return
			'Retrieves chat messages from a task discussion. '
			. 'Use this to read comments and conversation in the task chat. '
			. 'This tool is read-only: it does not post messages (see send_chat_message) '
			. 'and does not return the task change history (see get_task_history). '
			. 'Messages are returned newest first; use lastId to page into older messages'
		;
	}

	public function canList(int $userId): bool
	{
		return parent::canList($userId) && Loader::includeModule('im');
	}

	public function canRun(int $userId): bool
	{
		return parent::canRun($userId) && Loader::includeModule('im');
	}

	protected function executeStructured(int $userId, ...$args): array
	{
		$dto = GetChatMessagesDto::fromArray($args);

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
			return $this->chatProvider->getMessages($dto, $userId);
		}
		catch (InvalidIdentifierException)
		{
			throw new McpException('The provided task identifier is invalid');
		}
	}
}
