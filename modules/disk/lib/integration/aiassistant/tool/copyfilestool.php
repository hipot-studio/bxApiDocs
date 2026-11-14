<?php

declare(strict_types=1);

namespace Bitrix\Disk\Integration\AiAssistant\Tool;

final class CopyFilesTool extends BaseDiskTool
{
	public function getName(): string
	{
		return 'copy_files';
	}

	public function getDescription(): string
	{
		return 'Copies up to 50 exact Disk files to one exact destination without overwriting or generating suffixes. '
			. 'Batch, shared or foreign changes require confirmation.';
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
				'targetFolderId' => ['type' => 'integer', 'minimum' => 1],
				'targetStorageId' => ['type' => 'integer', 'minimum' => 1],
				'confirmed' => ['type' => 'boolean', 'default' => false],
			],
			'required' => ['fileIds'],
			'additionalProperties' => false,
		];
	}

	protected function executeStructured(int $userId, ...$args): array
	{
		$args = (array)$args;

		return $this->getDiskService()->copyObjects(
			$userId,
			'file',
			array_map('intval', (array)($args['fileIds'] ?? [])),
			$this->intArg($args, 'targetFolderId'),
			$this->intArg($args, 'targetStorageId'),
			$this->boolArg($args, 'confirmed'),
		);
	}
}
