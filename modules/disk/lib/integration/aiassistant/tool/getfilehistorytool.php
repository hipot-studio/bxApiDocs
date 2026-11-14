<?php

declare(strict_types=1);

namespace Bitrix\Disk\Integration\AiAssistant\Tool;

final class GetFileHistoryTool extends BaseDiskTool
{
	public function getName(): string
	{
		return 'get_file_history';
	}

	public function getDescription(): string
	{
		return 'Returns Bitrix24 Disk file version history. Prefer fileId from search_files; when fileId and fileName are both passed, they must identify the same file. fileName uses Disk full-text search; pass storageId or folderId to narrow it. timeline and full return version metadata; editors returns unique editors with the latest available edit date and page-scoped editorStats. Inline editor comments are unavailable; asksAboutComments returns this explicit limitation.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'fileId' => ['type' => 'integer', 'minimum' => 1, 'description' => 'File ID. When fileName or storageId is also passed, all values must match.'],
				'fileName' => [
					'type' => 'string',
					'minLength' => 1,
					'description' => 'File name search query. Prefer fileId from search_files; storageId or folderId narrows the search.',
				],
				'storageId' => ['type' => 'integer', 'minimum' => 1],
				'folderId' => ['type' => 'integer', 'minimum' => 1],
				'mode' => ['type' => 'string', 'enum' => ['editors', 'timeline', 'full'], 'default' => 'timeline'],
				'periodDays' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 3650],
				'asksAboutComments' => ['type' => 'boolean', 'default' => false],
				'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 50, 'default' => 20],
				'offset' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 500, 'default' => 0],
			],
			'additionalProperties' => false,
		];
	}

	protected function executeStructured(int $userId, ...$args): array
	{
		$args = (array)$args;

		return $this->getDiskService()->getFileHistory(
			$userId,
			$this->intArg($args, 'fileId'),
			$this->stringArg($args, 'fileName'),
			$this->intArg($args, 'storageId'),
			$this->intArg($args, 'folderId'),
			(string)($args['mode'] ?? 'timeline'),
			$this->intArg($args, 'periodDays'),
			$this->boolArg($args, 'asksAboutComments'),
			$this->limitArg($args),
			$this->offsetArg($args),
		);
	}
}
