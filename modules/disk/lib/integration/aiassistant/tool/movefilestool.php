<?php

declare(strict_types=1);

namespace Bitrix\Disk\Integration\AiAssistant\Tool;

final class MoveFilesTool extends BaseDiskTool
{
	public function getName(): string
	{
		return 'move_files';
	}

	public function getDescription(): string
	{
		return 'Moves up to 50 exact Disk files to exactly one targetFolderId or targetStorageId '
			. 'without overwriting or generating suffixes. Batch, shared or foreign changes require confirmation.';
	}

	public function getInputSchema(): array
	{
		return $this->getMoveSchema();
	}

	protected function executeStructured(int $userId, ...$args): array
	{
		$args = (array)$args;

		return $this->getDiskService()->moveObjects(
			$userId,
			'file',
			array_map('intval', (array)($args['fileIds'] ?? [])),
			$this->intArg($args, 'targetFolderId'),
			$this->intArg($args, 'targetStorageId'),
			$this->boolArg($args, 'confirmed'),
		);
	}

	private function getMoveSchema(): array
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
				'targetFolderId' => ['type' => 'integer', 'minimum' => 1],
				'targetStorageId' => ['type' => 'integer', 'minimum' => 1],
				'confirmed' => ['type' => 'boolean', 'default' => false],
			],
			'required' => ['fileIds'],
			'additionalProperties' => false,
		];
	}
}
