<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\Tool;

use Bitrix\AiAssistant\Facade\TracedLogger;
use Bitrix\Main\Loader;
use Bitrix\Main\Validation\ValidationService;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Exception\AccessDeniedException;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Exception\InvalidIdentifierException;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Exception\NotFoundException;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\ChatService;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\Dto\SendChatMessageDto;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Exception\DtoValidationException;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\SchemaBuilder\ChatSchemaBuilder;

class SendChatMessageTool extends BaseTool
{
	public const ACTION_NAME = 'send_chat_message';

	public function __construct(
		private readonly ChatService $chatService,
		ChatSchemaBuilder $schemaBuilder,
		ValidationService $validationService,
		TracedLogger $tracedLogger,
	)
	{
		parent::__construct($schemaBuilder, $validationService, $tracedLogger);
	}

	public function getDescription(): string
	{
		return 'Sends a free‐form message in the task’s chat on behalf of the current user.';
	}

	public function canList(int $userId): bool
	{
		return parent::canList($userId) && Loader::includeModule('im');
	}

	public function canRun(int $userId): bool
	{
		return parent::canRun($userId) && Loader::includeModule('im');
	}

	protected function execute(int $userId, ...$args): string
	{
		$dto = SendChatMessageDto::fromArray($args);

		try
		{
			$this->validate($dto);

			$result = $this->chatService->sendMessage($dto, $userId);
		}
		catch (DtoValidationException $e)
		{
			return $this->createFailureResponse($e->getMessage());
		}
		catch (AccessDeniedException)
		{
			return $this->createFailureResponse('Access denied.');
		}
		catch (NotFoundException)
		{
			return $this->createFailureResponse('The task or chat for the task does not exist.');
		}
		catch (InvalidIdentifierException)
		{
			return $this->createFailureResponse('The provided task identifier is invalid.');
		}

		if (!$result)
		{
			return $this->createFailureResponse('Failed to send message in the task chat.');
		}

		return 'Chat message for task successfully sent.';
	}
}
