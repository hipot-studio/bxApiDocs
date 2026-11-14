<?php

declare(strict_types=1);

namespace Bitrix\Disk\Integration\AiAssistant\Tool;

final class CreatePublicLinkTool extends BaseDiskTool
{
	public function getName(): string
	{
		return 'create_public_link';
	}

	public function getDescription(): string
	{
		return 'Previews and, only after explicit confirmation, creates a manual public link for one exact Disk file. '
			. 'An existing manual link is returned without creating a duplicate.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'fileId' => ['type' => 'integer', 'minimum' => 1],
				'confirmed' => [
					'type' => 'boolean',
					'default' => false,
					'description' => 'Set true only after explicit confirmation of the exact server preview.',
				],
			],
			'required' => ['fileId'],
			'additionalProperties' => false,
		];
	}

	protected function executeStructured(int $userId, ...$args): array
	{
		$args = (array)$args;

		return $this->getDiskService()->createPublicLink(
			$userId,
			$this->intArg($args, 'fileId') ?? 0,
			$this->boolArg($args, 'confirmed'),
		);
	}
}
