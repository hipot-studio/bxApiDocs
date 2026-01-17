<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\SchemaBuilder;

use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\Tool\AddResultTool;

class ResultSchemaBuilder extends BaseSchemaBuilder
{
	protected function getProperties(?string $action): array
	{
		return match ($action)
		{
			AddResultTool::ACTION_NAME => $this->buildAddResultProperties(),
			default => [],
		};
	}

	protected function getRequiredFields(?string $action): array
	{
		return match ($action)
		{
			AddResultTool::ACTION_NAME => ['taskId', 'text'],
			default => [],
		};
	}

	private function buildAddResultProperties(): array
	{
		return [
			'taskId' => [
				'type' => 'integer',
				'description' => 'Identifier of the task. Must be a positive integer.',
				'minimum' => 1,
			],
			'text' => [
				'type' => 'string',
				'description' => 'The result text. Must not be an empty string.',
				'minLength' => 1,
			],
		];
	}
}
