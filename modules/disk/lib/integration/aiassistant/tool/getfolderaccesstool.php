<?php

declare(strict_types=1);

namespace Bitrix\Disk\Integration\AiAssistant\Tool;

final class GetFolderAccessTool extends BaseDiskTool
{
	public function getName(): string
	{
		return 'get_folder_access';
	}

	public function getDescription(): string
	{
		return 'Returns effective read, edit or full access to a Bitrix24 Disk folder. Access is available only to users who can change folder rights; internal task and inheritance details are not exposed. Use folderId when known. If folderName is used, pass storageId.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'folderId' => ['type' => 'integer', 'minimum' => 1],
				'folderName' => [
					'type' => 'string',
					'minLength' => 1,
					'description' => 'Exact folder name. Requires storageId; prefer folderId when known.',
				],
				'storageId' => ['type' => 'integer', 'minimum' => 1],
			],
			'additionalProperties' => false,
		];
	}

	protected function executeStructured(int $userId, ...$args): array
	{
		$args = (array)$args;

		return $this->getDiskService()->getFolderAccess(
			$userId,
			$this->intArg($args, 'folderId'),
			$this->stringArg($args, 'folderName'),
			$this->intArg($args, 'storageId'),
		);
	}
}
