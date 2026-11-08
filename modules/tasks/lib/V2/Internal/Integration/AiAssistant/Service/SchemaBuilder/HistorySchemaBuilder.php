<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\SchemaBuilder;

use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\Dto\GetTaskHistoryDto;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\Tool\GetTaskHistoryTool;

class HistorySchemaBuilder extends BaseSchemaBuilder
{
	protected function getProperties(?string $action): array
	{
		return match ($action)
		{
			GetTaskHistoryTool::ACTION_NAME => $this->buildGetTaskHistoryProperties(),
			default => [],
		};
	}

	protected function getRequiredFields(?string $action): array
	{
		return match ($action)
		{
			GetTaskHistoryTool::ACTION_NAME => ['taskId'],
			default => [],
		};
	}

	private function buildGetTaskHistoryProperties(): array
	{
		return [
			'taskId' => [
				'type' => 'integer',
				'description' => 'Identifier of the task',
				'minimum' => 1,
			],
			'limit' => [
				'type' => ['integer', 'null'],
				'description' =>
					'Maximum number of history records to return, newest first. '
					. 'Prefer a small limit when only the most recent changes matter; '
					. 'request more for a fuller audit'
				,
				'minimum' => GetTaskHistoryDto::LIMIT_MIN,
				'maximum' => GetTaskHistoryDto::LIMIT_MAX,
				'default' => GetTaskHistoryDto::LIMIT_DEFAULT,
			],
			'offset' => [
				'type' => ['integer', 'null'],
				'description' =>
					'Number of records to skip from the most recent change. '
					. 'Use with limit to page further into the past '
					. '(e.g., offset=20 with limit=20 returns the next 20 older records)'
				,
				'minimum' => GetTaskHistoryDto::OFFSET_MIN,
				'maximum' => GetTaskHistoryDto::OFFSET_MAX,
				'default' => 0,
			],
		];
	}
}
