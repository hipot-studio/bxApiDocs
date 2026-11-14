<?php

declare(strict_types=1);

namespace Bitrix\Disk\Integration\AiAssistant\Tool;

final class RestoreFilesFromTrashTool extends BaseDiskTool
{
	public function getName(): string
	{
		return 'restore_files_from_trash';
	}

	public function getDescription(): string
	{
		return 'Restores exact Disk files from trash when the original location has no name conflict. It never renames or overwrites an existing object.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'fileIds' => [
					'type' => 'array',
					'items' => ['type' => 'integer', 'minimum' => 1],
					'minItems' => 1,
					'maxItems' => 50,
					'uniqueItems' => true,
				],
				'confirmed' => ['type' => 'boolean', 'default' => false],
			],
			'required' => ['fileIds'],
			'additionalProperties' => false,
		];
	}

	protected function executeStructured(int $userId, ...$args): array
	{
		$args = (array)$args;

		return $this->getDiskService()->restoreObjectsFromTrash(
			$userId,
			'file',
			array_map('intval', (array)($args['fileIds'] ?? [])),
			$this->boolArg($args, 'confirmed'),
		);
	}
}
