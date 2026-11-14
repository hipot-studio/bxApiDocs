<?php

declare(strict_types=1);

namespace Bitrix\Disk\Integration\AiAssistant\Tool;

final class RenameFoldersTool extends BaseDiskTool
{
	public function getName(): string
	{
		return 'rename_folders';
	}

	public function getDescription(): string
	{
		return 'Renames up to 50 exact Disk folders without recreating them or generating unique suffixes. '
			. 'Batch, shared or foreign objects require confirmation of the server preview.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'folders' => [
					'type' => 'array',
					'items' => [
						'type' => 'object',
						'properties' => [
							'folderId' => ['type' => 'integer', 'minimum' => 1],
							'newName' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 255],
						],
						'required' => ['folderId', 'newName'],
						'additionalProperties' => false,
					],
					'minItems' => 1,
					'maxItems' => 50,
				],
				'confirmed' => ['type' => 'boolean', 'default' => false],
			],
			'required' => ['folders'],
			'additionalProperties' => false,
		];
	}

	protected function executeStructured(int $userId, ...$args): array
	{
		$args = (array)$args;
		$items = array_map(
			static fn(array $item): array => [
				'id' => (int)($item['folderId'] ?? 0),
				'newName' => (string)($item['newName'] ?? ''),
			],
			(array)($args['folders'] ?? []),
		);

		return $this->getDiskService()->renameObjects(
			$userId,
			'folder',
			$items,
			$this->boolArg($args, 'confirmed'),
		);
	}
}
