<?php

declare(strict_types=1);

namespace Bitrix\Disk\Integration\AiAssistant\Tool;

final class GetFolderContentsTool extends BaseDiskTool
{
	public function getName(): string
	{
		return 'get_folder_contents';
	}

	public function getDescription(): string
	{
		return 'Lists files and folders inside a Bitrix24 Disk folder visible to the current user.'
			. ' Use folderId when known. If folderId and folderName are both passed, they must identify the same folder. If folderName is used alone, pass storageId or storageName.'
			. ' Pass storageId or storageName alone to list the root folder of that storage.'
			. ' Call without folderId, folderName, storageId and storageName to list the root folder of the personal Disk of the current user.'
			. ' Returns direct children only, with pagination metadata. Only supported Disk storages are accepted.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'folderId' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Folder ID. When folderName, storageId or storageName is also passed, all values must match.'],
				'folderName' => [
					'type' => 'string',
					'minLength' => 1,
					'description' => 'Exact folder name. Requires storageId or storageName; prefer folderId when known.',
				],
				'storageId' => [
					'type' => 'integer',
					'minimum' => 1,
					'description' => 'Storage ID. When storageName is also passed, both values must match. Without folderId and folderName lists the root folder of this storage.',
				],
				'storageName' => [
					'type' => 'string',
					'minLength' => 1,
					'description' => 'Exact visible storage name. Ambiguous matches are returned as candidates.',
				],
				'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 50, 'default' => 20],
				'offset' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 500, 'default' => 0],
			],
			'additionalProperties' => false,
		];
	}

	protected function executeStructured(int $userId, ...$args): array
	{
		$args = (array)$args;

		return $this->getDiskService()->getFolderContents(
			$userId,
			$this->intArg($args, 'folderId'),
			$this->stringArg($args, 'folderName'),
			$this->intArg($args, 'storageId'),
			$this->limitArg($args),
			$this->offsetArg($args),
			$this->stringArg($args, 'storageName'),
		);
	}
}
