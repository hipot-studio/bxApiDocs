<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\SchemaBuilder;

use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\Tool\CheckList\CreateCheckListItemTool;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\Tool\CheckList\CreateCheckListTool;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\Tool\CheckList\DeleteCheckListItemTool;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\Tool\CheckList\DeleteCheckListTool;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\Tool\CheckList\UpdateCheckListItemTool;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\Tool\CheckList\UpdateCheckListTool;

class CheckListSchemaBuilder extends BaseSchemaBuilder
{
	protected function getProperties(?string $action): array
	{
		return match ($action)
		{
			CreateCheckListTool::ACTION_NAME => $this->buildCreateCheckListProperties(),
			CreateCheckListItemTool::ACTION_NAME => $this->buildCreateCheckListItemProperties(),
			UpdateCheckListTool::ACTION_NAME => $this->buildUpdateCheckListProperties(),
			UpdateCheckListItemTool::ACTION_NAME => $this->buildUpdateCheckListItemProperties(),
			DeleteCheckListTool::ACTION_NAME => $this->buildDeleteCheckListProperties(),
			DeleteCheckListItemTool::ACTION_NAME => $this->buildDeleteCheckListItemProperties(),
			default => [],
		};
	}

	protected function getRequiredFields(?string $action): array
	{
		return match ($action)
		{
			UpdateCheckListTool::ACTION_NAME, DeleteCheckListTool::ACTION_NAME => ['checkListId'],
			CreateCheckListItemTool::ACTION_NAME => ['title', 'checkListId'],
			CreateCheckListTool::ACTION_NAME => ['taskId', 'title'],
			default => [],
		};
	}

	private function buildCreateCheckListProperties(): array
	{
		return [
			'taskId' => [
				'type' => 'integer',
				'description' => 'Identifier of the task. Must be a positive integer.',
				'minimum' => 1,
			],
			'title' => [
				'type' => 'string',
				'description' => 'Title of the checklist. Must not be an empty string.',
				'minLength' => 1,
			],
			'checkListItems' => [
				'type' => 'array',
				'items' => [
					'type' => 'string',
					'description' => 'Title of the checklist item. Must not be an empty string.',
					'minLength' => 1,
				],
				'description' => 'List of items to be added to the checklist.',
			],
		];
	}

	private function buildCreateCheckListItemProperties(): array
	{
		return [
			'title' => [
				'type' => 'string',
				'description' => 'Title of the checklist item. Must not be an empty string.',
				'minLength' => 1,
			],
			'checkListId' => [
				'type' => 'integer',
				'description' => 'Identifier of the checklist. Must not be null.',
				'minimum' => 1,
			],
		];
	}

	private function buildUpdateCheckListProperties(): array
	{
		return [
			'checkListId' => [
				'type' => 'integer',
				'description' => 'Identifier of the checklist. Must be a positive integer.',
				'minimum' => 1,
			],
			'title' => [
				'type' => ['string', 'null'],
				'description' => 'New title. Must be a non-empty string or null to leave it unchanged.',
				'minLength' => 1,
			],
			'sortIndex' => [
				'type' => ['integer', 'null'],
				'description' =>
					'New sort index for reordering the checklists. '
					. 'Must be a non-negative integer or null to leave it unchanged.'
				,
				'minimum' => 0,
			],
		];
	}

	private function buildUpdateCheckListItemProperties(): array
	{
		return [
			'itemId' => [
				'type' => 'integer',
				'description' => 'Identifier of the checklist item. Must be a positive integer.',
				'minimum' => 1,
			],
			'title' => [
				'type' => ['string', 'null'],
				'description' => 'New title. Must be a non-empty string or null to leave it unchanged.',
				'minLength' => 1,
			],
			'sortIndex' => [
				'type' => ['integer', 'null'],
				'description' =>
					'New sort index for reordering the checklist items. '
					. 'Must be a non-negative integer or null to leave it unchanged.'
				,
				'minimum' => 0,
			],
		];
	}

	private function buildDeleteCheckListProperties(): array
	{
		return [
			'checkListId' => [
				'type' => 'integer',
				'description' => 'Identifier of the checklist to delete. Must be a positive integer.',
				'minimum' => 1,
			],
		];
	}

	private function buildDeleteCheckListItemProperties(): array
	{
		return [
			'itemId' => [
				'type' => 'integer',
				'description' => 'Identifier of the checklist item to delete. Must be a positive integer.',
				'minimum' => 1,
			],
		];
	}
}
