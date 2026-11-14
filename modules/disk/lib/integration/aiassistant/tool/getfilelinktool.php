<?php

declare(strict_types=1);

namespace Bitrix\Disk\Integration\AiAssistant\Tool;

final class GetFileLinkTool extends BaseDiskTool
{
	public function getName(): string
	{
		return 'get_file_link';
	}

	public function getDescription(): string
	{
		return 'Returns an absolute URL-safe internal Bitrix24 Disk link for one readable standalone file. '
			. 'It does not create or change public access.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'fileId' => ['type' => 'integer', 'minimum' => 1],
				'fileName' => ['type' => 'string', 'minLength' => 1],
				'storageId' => ['type' => 'integer', 'minimum' => 1],
				'folderId' => ['type' => 'integer', 'minimum' => 1],
			],
			'additionalProperties' => false,
		];
	}

	protected function executeStructured(int $userId, ...$args): array
	{
		$args = (array)$args;

		return $this->getDiskService()->getFileLink(
			$userId,
			$this->intArg($args, 'fileId'),
			$this->stringArg($args, 'fileName'),
			$this->intArg($args, 'storageId'),
			$this->intArg($args, 'folderId'),
		);
	}
}
