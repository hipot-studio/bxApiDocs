<?php

declare(strict_types=1);

namespace Bitrix\Disk\Integration\AiAssistant\Tool;

final class GetCleanupCandidatesTool extends BaseDiskTool
{
	public function getName(): string
	{
		return 'get_cleanup_candidates';
	}

	public function getDescription(): string
	{
		return 'Returns a bounded, read-only list of cleanup candidates backed by available Disk evidence. It never deletes or changes objects; partial sources are reported explicitly.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'storageId' => ['type' => 'integer', 'minimum' => 1],
				'categories' => [
					'type' => 'array',
					'items' => ['type' => 'string', 'enum' => ['duplicate', 'old_large_copy', 'unnecessary_version', 'trash']],
					'minItems' => 1,
					'maxItems' => 4,
					'uniqueItems' => true,
				],
				'strategy' => [
					'type' => 'string',
					'enum' => ['safe', 'largest_first', 'oldest_first'],
					'default' => 'safe',
				],
				'serverLimit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 20],
			],
			'required' => ['storageId'],
			'additionalProperties' => false,
		];
	}

	protected function executeStructured(int $userId, ...$args): array
	{
		$args = (array)$args;

		return $this->getDiskService()->getCleanupCandidates(
			$userId,
			$this->intArg($args, 'storageId') ?? 0,
			array_values((array)($args['categories'] ?? [])),
			(string)($args['strategy'] ?? 'safe'),
			(int)($args['serverLimit'] ?? 20),
		);
	}
}
