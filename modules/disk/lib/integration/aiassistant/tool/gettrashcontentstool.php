<?php

declare(strict_types=1);

namespace Bitrix\Disk\Integration\AiAssistant\Tool;

final class GetTrashContentsTool extends BaseDiskTool
{
	public function getName(): string
	{
		return 'get_trash_contents';
	}

	public function getDescription(): string
	{
		return 'Lists a bounded page of readable root objects in a supported Disk storage trash without changing them.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'storageId' => ['type' => 'integer', 'minimum' => 1],
				'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 50],
				'offset' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 1000, 'default' => 0],
			],
			'required' => ['storageId'],
			'additionalProperties' => false,
		];
	}

	protected function executeStructured(int $userId, ...$args): array
	{
		$args = (array)$args;

		return $this->getDiskService()->getTrashContents(
			$userId,
			$this->intArg($args, 'storageId') ?? 0,
			(int)($args['limit'] ?? 50),
			(int)($args['offset'] ?? 0),
		);
	}
}
