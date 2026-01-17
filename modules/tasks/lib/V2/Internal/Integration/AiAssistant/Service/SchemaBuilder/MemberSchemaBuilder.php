<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\SchemaBuilder;

use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\Tool\Member\AddAccomplicesTool;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\Tool\Member\AddAuditorsTool;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\Tool\Member\AddCurrentUserAsAuditorTool;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\Tool\Member\DeleteAccomplicesTool;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\Tool\Member\DeleteAuditorsTool;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\Tool\SearchUsersTool;

class MemberSchemaBuilder extends BaseSchemaBuilder
{
	protected function getProperties(?string $action): array
	{
		return match ($action)
		{
			AddAccomplicesTool::ACTION_NAME => $this->buildAddAccomplicesProperties(),
			AddAuditorsTool::ACTION_NAME => $this->buildAddAuditorsProperties(),
			AddCurrentUserAsAuditorTool::ACTION_NAME => $this->buildAddCurrentUserAsAuditorProperties(),
			DeleteAccomplicesTool::ACTION_NAME => $this->buildDeleteAccomplicesProperties(),
			DeleteAuditorsTool::ACTION_NAME => $this->buildDeleteAuditorsProperties(),
			SearchUsersTool::ACTION_NAME => $this->buildSearchUsersProperties(),
			default => [],
		};
	}

	protected function getRequiredFields(?string $action): array
	{
		return match ($action)
		{
			AddCurrentUserAsAuditorTool::ACTION_NAME => ['taskId'],
			AddAccomplicesTool::ACTION_NAME, DeleteAccomplicesTool::ACTION_NAME => ['taskId', 'accompliceIds'],
			AddAuditorsTool::ACTION_NAME, DeleteAuditorsTool::ACTION_NAME => ['taskId', 'auditorIds'],
			SearchUsersTool::ACTION_NAME => ['query'],
			default => [],
		};
	}

	private function buildAddAccomplicesProperties(): array
	{
		return [
			'taskId' => $this->buildTaskIdProperty(),
			'accompliceIds' => [
				'type' => 'array',
				'items' => [
					'type' => 'integer',
					'description' => 'Identifier of the user to add as an accomplice. Must be a positive integer.',
					'minimum' => 1,
				],
				'description' => 'Array of user identifiers to add as accomplices. Must not be an empty array.',
				'minItems' => 1,
			],
		];
	}

	private function buildAddAuditorsProperties(): array
	{
		return [
			'taskId' => $this->buildTaskIdProperty(),
			'auditorIds' => [
				'type' => 'array',
				'items' => [
					'type' => 'integer',
					'description' => 'Identifier of the user to add as an auditor. Must be a positive integer.',
					'minimum' => 1,
				],
				'description' => 'Array of user identifiers to add as auditors. Must not be an empty array.',
				'minItems' => 1,
			],
		];
	}

	private function buildAddCurrentUserAsAuditorProperties(): array
	{
		return [
			'taskId' => $this->buildTaskIdProperty(),
		];
	}

	private function buildDeleteAccomplicesProperties(): array
	{
		return [
			'taskId' => $this->buildTaskIdProperty(),
			'accompliceIds' => [
				'type' => 'array',
				'items' => [
					'type' => 'integer',
					'description' => 'Identifier of the user to remove from accomplices. Must be a positive integer.',
					'minimum' => 1,
				],
				'description' => 'Array of user identifiers to remove from accomplices. Must not be an empty array.',
				'minItems' => 1,
			],
		];
	}

	private function buildDeleteAuditorsProperties(): array
	{
		return [
			'taskId' => $this->buildTaskIdProperty(),
			'auditorIds' => [
				'type' => 'array',
				'items' => [
					'type' => 'integer',
					'description' => 'Identifier of the user to remove from auditors. Must be a positive integer.',
					'minimum' => 1,
				],
				'description' => 'Array of user identifiers to remove from auditors. Must not be an empty array.',
				'minItems' => 1,
			],
		];
	}

	private function buildSearchUsersProperties(): array
	{
		return [
			'query' => [
				'type' => 'string',
				'description' => 'Search query or keyword. Must be a non-empty string.',
			],
		];
	}

	private function buildTaskIdProperty(): array
	{
		return [
			'type' => 'integer',
			'description' => 'Identifier of the task. Must be a positive integer.',
			'minimum' => 1,
		];
	}
}
