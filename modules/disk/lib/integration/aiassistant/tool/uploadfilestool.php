<?php

declare(strict_types=1);

namespace Bitrix\Disk\Integration\AiAssistant\Tool;

final class UploadFilesTool extends BaseDiskTool
{
	public function getName(): string
	{
		return 'upload_files';
	}

	public function getDescription(): string
	{
		return 'Uploads up to 20 canonical base64 files to exactly one folderId or storageId. '
			. 'For one ordinary upload to the current user personal Disk, use confirmed=false. '
			. 'Batch, shared or foreign destinations require the returned preview to be confirmed. '
			. 'An explicit upload request does not imply confirmed=true: use it only after a previous upload_files '
			. 'call returned confirmationRequired with the exact server preview and the user separately confirmed it. '
			. 'If the upload returns NAME_CONFLICT, treat it as terminal for the current user request: '
			. 'report it and do not call delete_files, rename_files, rename_folders, move_files, move_folders, '
			. 'copy_files, copy_folders, retry upload_files or perform any other write without a new explicit '
			. 'user instruction.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'files' => [
					'type' => 'array',
					'items' => [
						'type' => 'object',
						'properties' => [
							'name' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 255],
							'contentType' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 255],
							'contentBase64' => ['type' => 'string'],
						],
						'required' => ['name', 'contentType', 'contentBase64'],
						'additionalProperties' => false,
					],
					'minItems' => 1,
					'maxItems' => 20,
				],
				'folderId' => ['type' => 'integer', 'minimum' => 1],
				'storageId' => ['type' => 'integer', 'minimum' => 1],
				'confirmed' => [
					'type' => 'boolean',
					'default' => false,
					'description' => 'Set true only after explicit confirmation of the exact server preview.',
				],
			],
			'required' => ['files'],
			'additionalProperties' => false,
		];
	}

	protected function executeStructured(int $userId, ...$args): array
	{
		$args = (array)$args;

		return $this->getDiskService()->uploadFiles(
			$userId,
			(array)($args['files'] ?? []),
			$this->intArg($args, 'folderId'),
			$this->intArg($args, 'storageId'),
			$this->boolArg($args, 'confirmed'),
		);
	}
}
