<?php

declare(strict_types=1);

namespace Bitrix\Disk\Integration\AiAssistant\Tool;

final class GetStorageUsageTool extends BaseDiskTool
{
	public function getName(): string
	{
		return 'get_storage_usage';
	}

	public function getDescription(): string
	{
		return 'Reads an existing Disk Volume snapshot without starting or simulating recalculation. '
			. 'Use summary with storageId for one exact storage, including a personal Disk; omit storageId from '
			. 'summary only for an explicitly requested portal-wide administrator summary. by_storages is an '
			. 'administrator-only portal-wide comparison and must not be used for a single storage. If the response '
			. 'status or freshness is unavailable, treat it as final for the requested scope: report that no suitable '
			. 'snapshot exists. Do not retry with another usageType, remove storageId, widen the scope, or start or '
			. 'simulate recalculation unless the user explicitly asks for a different scope.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'usageType' => [
					'type' => 'string',
					'enum' => ['summary', 'by_users', 'by_folders', 'by_storages'],
					'description' => 'summary aggregates the requested scope; use it with storageId for one personal '
						. 'or other exact storage. by_folders requires storageId. by_users and '
						. 'by_storages are administrator-only portal-wide projections without storageId; by_storages '
						. 'compares multiple storages and is never a summary of one storage. Use search_files with '
						. 'searchType top_by_size to find the largest current files.',
				],
				'storageId' => [
					'type' => 'integer',
					'minimum' => 1,
					'description' => 'Exact storage scope. Pass it for summary when the user asks about one storage, '
						. 'including a personal Disk. It is required for by_folders, and forbidden for '
						. 'portal-wide by_users and by_storages.',
				],
				'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 50, 'default' => 10],
			],
			'required' => ['usageType'],
			'additionalProperties' => false,
		];
	}

	protected function executeStructured(int $userId, ...$args): array
	{
		$args = (array)$args;

		return $this->getDiskService()->getStorageUsage(
			$userId,
			(string)($args['usageType'] ?? ''),
			$this->intArg($args, 'storageId'),
			(int)($args['limit'] ?? 10),
		);
	}
}
