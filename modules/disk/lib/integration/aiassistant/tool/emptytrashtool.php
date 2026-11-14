<?php

declare(strict_types=1);

namespace Bitrix\Disk\Integration\AiAssistant\Tool;

final class EmptyTrashTool extends BaseDiskTool
{
	public function getName(): string
	{
		return 'empty_trash';
	}

	public function getDescription(): string
	{
		return 'Builds a complete bounded trash summary first and schedules irreversible background cleanup only '
			. 'after explicit confirmation. Keep the user explanation concise: uiVisibleRootObjectsCount is what the '
			. 'Disk interface shows at the top level; uiHiddenRootObjectsCount is the number of older top-level entries '
			. 'hidden by uiRetentionDays but still deleted by cleanup. rootObjectsCount is their combined top-level count, '
			. 'while objectsCount is the exact full number affected including recursive folder content. When hidden items '
			. 'exist, explain the visible, hidden and full affected counts in plain language without listing every item. '
			. 'Never present rootObjectsCount as the full affected count. '
			. 'The tool returns the task status immediately and does not wait for completion.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'storageId' => ['type' => 'integer', 'minimum' => 1],
				'confirmed' => ['type' => 'boolean', 'default' => false],
			],
			'required' => ['storageId'],
			'additionalProperties' => false,
		];
	}

	protected function executeStructured(int $userId, ...$args): array
	{
		$args = (array)$args;

		return $this->getDiskService()->emptyTrash(
			$userId,
			$this->intArg($args, 'storageId') ?? 0,
			$this->boolArg($args, 'confirmed'),
		);
	}
}
