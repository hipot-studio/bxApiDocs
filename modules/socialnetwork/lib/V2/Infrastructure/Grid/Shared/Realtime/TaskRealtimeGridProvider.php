<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Infrastructure\Grid\Shared\Realtime;

use Bitrix\Socialnetwork\V2\Internal\Service\Grid\ProjectListGridRowService;

class TaskRealtimeGridProvider
{
	public function __construct(
		private readonly ProjectListGridRowService $projectListGridRowService,
		private readonly TaskRealtimeGridRuntimeFactory $taskRealtimeGridRuntimeFactory,
		private readonly TaskRealtimePayloadBuilder $taskRealtimePayloadBuilder,
	)
	{
	}

	public function getLegacyGroupTaskCounters(array $readableIds): array
	{
		//@note: realtime not work
		return [];
	}

	public function getScrumTaskCounters(array $readableIds): array
	{
		//@note: realtime not work
		return [];
	}

	public function getLegacyGroupTaskGridRows(
		array $ids,
		array $readableIds,
		TaskRealtimePageContext $pageContext,
		int $page = 1,
		int $currentUserId = 0,
		bool $isAdmin = false,
	): array
	{
		$ids = $this->normalizePositiveIds($ids);
		if (empty($ids))
		{
			return [];
		}

		$result = array_fill_keys($ids, false);
		$readableIds = $this->normalizePositiveIds($readableIds);
		if (empty($readableIds))
		{
			return $result;
		}

		$runtime = $this->taskRealtimeGridRuntimeFactory->createProjectRuntime(
			pageContext: $pageContext,
			page: $page,
			currentUserId: $currentUserId,
		);
		if ($runtime === null)
		{
			return $result;
		}

		$rows = $this->projectListGridRowService->loadProjectRows(
			filter: $runtime->filter,
			sort: $runtime->sort,
			offset: $runtime->offset,
			limit: $runtime->limit,
			select: $runtime->select,
			withImage: $runtime->withImage,
			withMembers: $runtime->withMembers,
			withOwner: $runtime->withOwner,
			withTags: $runtime->withTags,
			withRelationDate: $runtime->withRelationDate,
			withViewDate: $runtime->withViewDate,
			currentUserId: $currentUserId,
			contextUserId: $runtime->contextUserId,
			isAdmin: $isAdmin,
		);
		$payload = $this->taskRealtimePayloadBuilder->build(
			runtime: $runtime,
			rows: $rows,
			targetIds: $readableIds,
			urlContext: $pageContext->urlContext,
		);

		return array_replace($result, $payload);
	}

	public function getScrumTaskGridRows(
		array $ids,
		array $readableIds,
		TaskRealtimePageContext $pageContext,
		int $page = 1,
		int $currentUserId = 0,
		bool $isAdmin = false,
	): array
	{
		$ids = $this->normalizePositiveIds($ids);
		if (empty($ids))
		{
			return [];
		}

		$result = array_fill_keys($ids, false);
		$readableIds = $this->normalizePositiveIds($readableIds);
		if (empty($readableIds))
		{
			return $result;
		}

		$runtime = $this->taskRealtimeGridRuntimeFactory->createScrumRuntime(
			pageContext: $pageContext,
			page: $page,
			currentUserId: $currentUserId,
		);
		if ($runtime === null)
		{
			return $result;
		}

		$rows = $this->projectListGridRowService->loadScrumRows(
			filter: $runtime->filter,
			sort: $runtime->sort,
			offset: $runtime->offset,
			limit: $runtime->limit,
			select: $runtime->select,
			withImage: $runtime->withImage,
			withMembers: $runtime->withMembers,
			withOwner: $runtime->withOwner,
			currentUserId: $currentUserId,
			contextUserId: $runtime->contextUserId,
			isAdmin: $isAdmin,
		);
		$payload = $this->taskRealtimePayloadBuilder->build(
			runtime: $runtime,
			rows: $rows,
			targetIds: $readableIds,
			urlContext: $pageContext->urlContext,
		);

		return array_replace($result, $payload);
	}

	/**
	 * @param mixed[] $ids
	 * @return int[]
	 */
	private function normalizePositiveIds(array $ids): array
	{
		$normalizedIds = array_map(static fn(mixed $id): int => (int)$id, $ids);
		$normalizedIds = array_filter($normalizedIds, static fn(int $id): bool => $id > 0);

		return array_values(array_unique($normalizedIds));
	}
}
