<?php

declare(strict_types=1);

namespace Bitrix\Disk\Integration\AiAssistant\Tool;

final class ManageFolderAccessTool extends BaseDiskTool
{
	public function getName(): string
	{
		return 'manage_folder_access';
	}

	public function getDescription(): string
	{
		return 'Grants or revokes explicit access on a Bitrix24 Disk folder for one or more finite access codes. Use folderId when known. If folderName is used, pass storageId. accessLevel is required for grant and must be provided by the user; the tool must not guess it. Both actions require explicit user confirmation after reviewing the exact preview.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'action' => ['type' => 'string', 'enum' => ['grant', 'revoke']],
				'folderId' => ['type' => 'integer', 'minimum' => 1],
				'folderName' => [
					'type' => 'string',
					'minLength' => 1,
					'description' => 'Exact folder name. Requires storageId; prefer folderId when known.',
				],
				'storageId' => ['type' => 'integer', 'minimum' => 1],
				'accessCode' => [
					'type' => 'string',
					'pattern' => '^(?:U\\d+|DR\\d+|SG\\d+_K)$',
					'description' => 'Shareable Disk entity code: U123, DR7 or SG45_K.',
				],
				'accessCodes' => [
					'type' => 'array',
					'items' => [
						'type' => 'string',
						'pattern' => '^(?:U\\d+|DR\\d+|SG\\d+_K)$',
					],
					'maxItems' => 50,
					'uniqueItems' => true,
					'description' => 'Additional finite access codes to process with accessCode.',
				],
				'accessLevel' => [
					'type' => 'string',
					'enum' => ['read', 'edit', 'full'],
					'description' => 'Required for grant. Ask the user if omitted.',
				],
				'confirmed' => [
					'type' => 'boolean',
					'default' => false,
					'description' => 'Keep false to receive the exact preview; set true only after explicit user confirmation.',
				],
			],
			'required' => ['action', 'accessCode'],
			'additionalProperties' => false,
		];
	}

	protected function executeStructured(int $userId, ...$args): array
	{
		$args = (array)$args;

		return $this->getDiskService()->manageFolderAccess(
			$userId,
			(string)$args['action'],
			$this->stringArg($args, 'accessCode') ?? '',
			$this->stringArg($args, 'accessLevel'),
			$this->intArg($args, 'folderId'),
			$this->stringArg($args, 'folderName'),
			$this->intArg($args, 'storageId'),
			$this->boolArg($args, 'confirmed'),
			(array)($args['accessCodes'] ?? []),
		);
	}
}
