<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\SchemaBuilder;

use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\Tool\SendChatMessageTool;

class ChatSchemaBuilder extends BaseSchemaBuilder
{
	protected function getProperties(?string $action): array
	{
		return match ($action)
		{
			SendChatMessageTool::ACTION_NAME => $this->buildSendMessageProperties(),
			default => [],
		};
	}

	protected function getRequiredFields(?string $action): array
	{
		return match ($action)
		{
			SendChatMessageTool::ACTION_NAME => ['taskId', 'text'],
			default => [],
		};
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
				'description' => 'Text to send. Must not be an empty string.',
				'minLength' => 1,
			],
		];
	}
}
