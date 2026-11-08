<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\SchemaBuilder;

use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\Dto\GetChatMessagesDto;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\Tool\GetChatMessagesTool;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\Tool\SendChatMessageTool;

class ChatSchemaBuilder extends BaseSchemaBuilder
{
	protected function getProperties(?string $action): array
	{
		return match ($action)
		{
			GetChatMessagesTool::ACTION_NAME => $this->buildGetChatMessagesProperties(),
			SendChatMessageTool::ACTION_NAME => $this->buildSendMessageProperties(),
			default => [],
		};
	}

	protected function getRequiredFields(?string $action): array
	{
		return match ($action)
		{
			GetChatMessagesTool::ACTION_NAME => ['taskId'],
			SendChatMessageTool::ACTION_NAME => ['taskId', 'text'],
			default => [],
		};
	}

	private function buildGetChatMessagesProperties(): array
	{
		return [
			'taskId' => [
				'type' => 'integer',
				'description' => 'Identifier of the task whose chat messages should be retrieved',
				'minimum' => 1,
			],
			'limit' => [
				'type' => ['integer', 'null'],
				'description' =>
					'Maximum number of messages to return. '
					. 'Prefer a small limit to inspect the most recent messages first; '
					. 'request more only when the user explicitly asks for a longer history'
				,
				'minimum' => GetChatMessagesDto::LIMIT_MIN,
				'maximum' => GetChatMessagesDto::LIMIT_MAX,
				'default' => GetChatMessagesDto::LIMIT_DEFAULT,
			],
			'lastId' => [
				'type' => ['integer', 'null'],
				'description' =>
					'Opaque pagination cursor for loading older messages. '
					. 'Returns messages strictly older than this ID (exclusive). '
					. 'Pass the nextLastId value from the previous response as-is. '
					. 'Do not modify or compute it. Omit or pass null for the first page (most recent messages)'
				,
				'minimum' => 1,
				'default' => null,
			],
		];
	}

	private function buildSendMessageProperties(): array
	{
		return [
			'taskId' => [
				'type' => 'integer',
				'description' => 'Identifier of the task whose chat to post into. Must be a positive integer.',
				'minimum' => 1,
			],
			'text' => [
				'type' => 'string',
				'description' => 'Text to send. Must not be an empty string.' . BaseSchemaBuilder::FORMATTING_NOTE,
				'minLength' => 1,
			],
		];
	}
}
