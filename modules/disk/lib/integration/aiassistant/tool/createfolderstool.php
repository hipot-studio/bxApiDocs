<?php

declare(strict_types=1);

namespace Bitrix\Disk\Integration\AiAssistant\Tool;

final class CreateFoldersTool extends BaseDiskTool
{
	public function getName(): string
	{
		return 'create_folders';
	}

	public function getDescription(): string
	{
		return 'Creates up to 20 folders without automatic name suffixes. '
			. 'Pass either folderId or storageId for an explicit destination, or omit both to use the authenticated '
			. "user's personal Disk root; never pass both. One folder in that personal root is created immediately; "
			. 'batch, shared or foreign destinations require confirmation.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'names' => [
					'type' => 'array',
					'items' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 255],
					'minItems' => 1,
					'maxItems' => 20,
				],
				'folderId' => ['type' => 'integer', 'minimum' => 1],
				'storageId' => ['type' => 'integer', 'minimum' => 1],
				'confirmed' => ['type' => 'boolean', 'default' => false],
			],
			'required' => ['names'],
			'additionalProperties' => false,
		];
	}

	protected function executeStructured(int $userId, ...$args): array
	{
		$args = (array)$args;

		return $this->getDiskService()->createFolders(
			$userId,
			(array)($args['names'] ?? []),
			$this->intArg($args, 'folderId'),
			$this->intArg($args, 'storageId'),
			$this->boolArg($args, 'confirmed'),
		);
	}
}
