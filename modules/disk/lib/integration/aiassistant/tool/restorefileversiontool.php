<?php

declare(strict_types=1);

namespace Bitrix\Disk\Integration\AiAssistant\Tool;

final class RestoreFileVersionTool extends BaseDiskTool
{
	public function getName(): string
	{
		return 'restore_file_version';
	}

	public function getDescription(): string
	{
		return 'Restores one exact Disk file from one version by versionId or an unambiguous versionDate. '
			. 'Shared or foreign files require confirmation of the current server preview.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'fileId' => ['type' => 'integer', 'minimum' => 1],
				'versionId' => ['type' => 'integer', 'minimum' => 1],
				'versionDate' => [
					'type' => 'string',
					'format' => 'date-time',
					'description' => 'Use only when versionId is unavailable; the date must match exactly one current version.',
				],
				'confirmed' => ['type' => 'boolean', 'default' => false],
			],
			'required' => ['fileId'],
			'additionalProperties' => false,
		];
	}

	protected function executeStructured(int $userId, ...$args): array
	{
		$args = (array)$args;

		return $this->getDiskService()->restoreFileVersion(
			$userId,
			$this->intArg($args, 'fileId') ?? 0,
			$this->intArg($args, 'versionId'),
			$this->stringArg($args, 'versionDate'),
			$this->boolArg($args, 'confirmed'),
		);
	}
}
