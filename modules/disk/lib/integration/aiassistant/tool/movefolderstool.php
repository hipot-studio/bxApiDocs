<?php

declare(strict_types=1);

namespace Bitrix\Disk\Integration\AiAssistant\Tool;

final class MoveFoldersTool extends BaseDiskTool
{
	public function getName(): string
	{
		return 'move_folders';
	}

	public function getDescription(): string
	{
		return 'Moves up to 50 exact Disk folders to one exact destination without overwriting, '
			. 'generating suffixes or allowing a folder to be moved into its own subtree.';
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
				'targetFolderId' => ['type' => 'integer', 'minimum' => 1],
				'targetStorageId' => ['type' => 'integer', 'minimum' => 1],
				'confirmed' => ['type' => 'boolean', 'default' => false],
			],
			'required' => ['folderIds'],
			'additionalProperties' => false,
		];
	}

	protected function executeStructured(int $userId, ...$args): array
	{
		$args = (array)$args;

		return $this->getDiskService()->moveObjects(
			$userId,
			'folder',
			array_map('intval', (array)($args['folderIds'] ?? [])),
			$this->intArg($args, 'targetFolderId'),
			$this->intArg($args, 'targetStorageId'),
			$this->boolArg($args, 'confirmed'),
		);
	}
}
