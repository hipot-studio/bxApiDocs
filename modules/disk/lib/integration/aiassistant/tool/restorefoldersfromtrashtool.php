<?php

declare(strict_types=1);

namespace Bitrix\Disk\Integration\AiAssistant\Tool;

final class RestoreFoldersFromTrashTool extends BaseDiskTool
{
	public function getName(): string
	{
		return 'restore_folders_from_trash';
	}

	public function getDescription(): string
	{
		return 'Restores exact Disk folders from trash when the original location has no name conflict. It never renames or overwrites an existing object.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'folderIds' => [
					'type' => 'array',
					'items' => ['type' => 'integer', 'minimum' => 1],
					'minItems' => 1,
					'maxItems' => 50,
					'uniqueItems' => true,
				],
				'confirmed' => ['type' => 'boolean', 'default' => false],
			],
			'required' => ['folderIds'],
			'additionalProperties' => false,
		];
	}

	protected function executeStructured(int $userId, ...$args): array
	{
		$args = (array)$args;

		return $this->getDiskService()->restoreObjectsFromTrash(
			$userId,
			'folder',
			array_map('intval', (array)($args['folderIds'] ?? [])),
			$this->boolArg($args, 'confirmed'),
		);
	}
}
