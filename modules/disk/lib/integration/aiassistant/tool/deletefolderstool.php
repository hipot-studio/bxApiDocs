<?php

declare(strict_types=1);

namespace Bitrix\Disk\Integration\AiAssistant\Tool;

final class DeleteFoldersTool extends BaseDiskTool
{
	public function getName(): string
	{
		return 'delete_folders';
	}

	public function getDescription(): string
	{
		return 'Previews deletion of up to 20 folders before any effect. Each target deletes the whole folder '
			. 'with all recursive content. For target status=ready, treeObjectsCount is the exact full number of '
			. 'affected objects including the folder itself. For status=limit_exceeded, the tree exceeds the supported '
			. 'limit: the count is limited by the validation threshold and is not the full tree size; do not ask for '
			. 'confirmation because the operation cannot run. The preview has no itemized list of the tree: do not '
			. 'present separately known children as exhaustive and never invent names. Soft deletion moves folders '
			. 'to trash; permanent deletion is irreversible. Set confirmed true only after reviewing the exact server '
			. 'preview.';
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
					'maxItems' => 20,
					'uniqueItems' => true,
				],
				'deletionType' => ['type' => 'string', 'enum' => ['soft', 'permanent'], 'default' => 'soft'],
				'confirmed' => ['type' => 'boolean', 'default' => false],
			],
			'required' => ['folderIds'],
			'additionalProperties' => false,
		];
	}

	protected function executeStructured(int $userId, ...$args): array
	{
		$args = (array)$args;

		return $this->getDiskService()->deleteFolders(
			$userId,
			array_map('intval', (array)($args['folderIds'] ?? [])),
			(string)($args['deletionType'] ?? 'soft'),
			$this->boolArg($args, 'confirmed'),
		);
	}
}
