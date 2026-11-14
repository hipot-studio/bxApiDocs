<?php

declare(strict_types=1);

namespace Bitrix\Disk\Integration\AiAssistant\Tool;

final class DeleteFilesTool extends BaseDiskTool
{
	public function getName(): string
	{
		return 'delete_files';
	}

	public function getDescription(): string
	{
		return 'Previews or deletes up to 100 Bitrix24 Disk files with per-item outcomes. '
			. 'Select it only when the current explicit user request is to delete specific files. '
			. 'Never use it to resolve NAME_CONFLICT or an upload, create, rename, move or copy error: '
			. 'neither an upload request nor a conflict is a request to delete. First call with confirmed=false. '
			. 'Set confirmed=true only after this same delete_files call returned confirmationRequired with the exact '
			. 'preview and the user separately confirmed it. deletionType defaults to to_trash; permanent deletion '
			. 'is irreversible.';
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
					'maxItems' => 100,
				],
				'deletionType' => [
					'type' => 'string',
					'enum' => ['to_trash', 'permanent'],
					'default' => 'to_trash',
				],
				'confirmed' => [
					'type' => 'boolean',
					'description' => 'Keep false to receive the exact preview and item outcomes; set true only after explicit user confirmation.',
				],
			],
			'required' => ['fileIds', 'confirmed'],
			'additionalProperties' => false,
		];
	}

	protected function executeStructured(int $userId, ...$args): array
	{
		$args = (array)$args;
		$fileIds = array_map('intval', (array)($args['fileIds'] ?? []));

		return $this->getDiskService()->deleteFiles(
			$userId,
			$fileIds,
			(string)($args['deletionType'] ?? 'to_trash'),
			$this->boolArg($args, 'confirmed'),
		);
	}
}
