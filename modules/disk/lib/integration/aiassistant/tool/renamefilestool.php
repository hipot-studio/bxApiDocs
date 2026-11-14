<?php

declare(strict_types=1);

namespace Bitrix\Disk\Integration\AiAssistant\Tool;

final class RenameFilesTool extends BaseDiskTool
{
	public function getName(): string
	{
		return 'rename_files';
	}

	public function getDescription(): string
	{
		return 'Renames up to 50 exact Disk files without recreating them or generating unique suffixes. '
			. 'Batch, shared or foreign objects require confirmation of the server preview.';
	}

	public function getInputSchema(): array
	{
		return $this->getRenameSchema('fileId');
	}

	protected function executeStructured(int $userId, ...$args): array
	{
		$args = (array)$args;
		$items = array_map(
			static fn(array $item): array => [
				'id' => (int)($item['fileId'] ?? 0),
				'newName' => (string)($item['newName'] ?? ''),
			],
			(array)($args['files'] ?? []),
		);

		return $this->getDiskService()->renameObjects(
			$userId,
			'file',
			$items,
			$this->boolArg($args, 'confirmed'),
		);
	}

	private function getRenameSchema(string $idField): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'files' => [
					'type' => 'array',
					'items' => [
						'type' => 'object',
						'properties' => [
							$idField => ['type' => 'integer', 'minimum' => 1],
							'newName' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 255],
						],
						'required' => [$idField, 'newName'],
						'additionalProperties' => false,
					],
					'minItems' => 1,
					'maxItems' => 50,
				],
				'confirmed' => ['type' => 'boolean', 'default' => false],
			],
			'required' => ['files'],
			'additionalProperties' => false,
		];
	}
}
