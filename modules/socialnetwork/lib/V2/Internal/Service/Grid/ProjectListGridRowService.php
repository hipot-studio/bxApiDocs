<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Service\Grid;

use Bitrix\Main\ORM\Query\Filter\ConditionTree;
use Bitrix\Socialnetwork\Helper\Workgroup\Access;
use Bitrix\Socialnetwork\V2\Internal\Access\Service\LegacyGroupAccessService;
use Bitrix\Socialnetwork\V2\Internal\Access\Service\ScrumAccessService;
use Bitrix\Socialnetwork\V2\Internal\Entity\User\Role;
use Bitrix\Socialnetwork\V2\Internal\Entity\Workgroup\WorkgroupPinMode;
use Bitrix\Socialnetwork\V2\Internal\Entity\Workgroup\WorkgroupUserRelation;
use Bitrix\Socialnetwork\V2\Internal\Integration\Im\Provider\ProjectChatDataProvider;
use Bitrix\Socialnetwork\V2\Internal\Integration\Tasks\Service\ProjectEfficiencyService;
use Bitrix\Socialnetwork\V2\Internal\Repository\FavoritesRepositoryInterface;
use Bitrix\Socialnetwork\V2\Internal\Repository\ProjectMemberRepositoryInterface;
use Bitrix\Socialnetwork\V2\Internal\Service\ProjectService;
use Bitrix\Socialnetwork\V2\Internal\Service\ScrumService;
use Bitrix\Socialnetwork\V2\Internal\Service\WorkgroupPinService;

class ProjectListGridRowService
{
	public function __construct(
		private readonly ProjectService $projectService,
		private readonly ScrumService $scrumService,
		private readonly ProjectEfficiencyService $projectEfficiencyService,
		private readonly LegacyGroupAccessService $legacyGroupAccessService,
		private readonly ScrumAccessService $scrumAccessService,
		private readonly ProjectMemberRepositoryInterface $projectMemberRepository,
		private readonly WorkgroupPinService $workgroupPinService,
		private readonly FavoritesRepositoryInterface $favoritesRepository,
		private readonly ProjectChatDataProvider $projectChatDataProvider,
	)
	{
	}

	public function loadProjectRows(
		?ConditionTree $filter = null,
		array $sort = [],
		?int $offset = null,
		?int $limit = null,
		array $select = ['*'],
		bool $withImage = false,
		bool $withMembers = false,
		bool $withOwner = false,
		bool $withTags = false,
		bool $withRelationDate = false,
		bool $withViewDate = false,
		int $currentUserId = 0,
		int $contextUserId = 0,
		bool $isAdmin = false,
		WorkgroupPinMode $pinMode = WorkgroupPinMode::TasksProject,
	): array
	{
		$contextUserId = ($contextUserId > 0 ? $contextUserId : $currentUserId);

		$gridRows = $this->projectService->getGridList(
			filter: $filter,
			sort: $sort,
			offset: $offset,
			limit: $limit,
			select: $select,
			withImage: $withImage,
			withMembers: $withMembers,
			withOwner: $withOwner,
			withTags: $withTags,
			withRelationDate: $withRelationDate,
			withViewDate: $withViewDate,
			currentUserId: $currentUserId,
			contextUserId: $contextUserId,
			isAdmin: $isAdmin,
			pinUserId: $this->getPinUserIdForCurrentContext($currentUserId, $contextUserId),
			pinMode: $pinMode,
		);

		$rows = array_map(static fn($gridRow): array => $gridRow->toArray(), $gridRows);

		$this->fillProjectEfficiency($rows);
		$this->hydrateRows(
			rows: $rows,
			currentUserId: $currentUserId,
			contextUserId: $contextUserId,
			isScrum: false,
			pinMode: $pinMode,
		);

		return $rows;
	}

	public function loadScrumRows(
		?ConditionTree $filter = null,
		array $sort = [],
		?int $offset = null,
		?int $limit = null,
		array $select = ['*'],
		bool $withImage = false,
		bool $withMembers = false,
		bool $withOwner = false,
		int $currentUserId = 0,
		int $contextUserId = 0,
		bool $isAdmin = false,
		WorkgroupPinMode $pinMode = WorkgroupPinMode::TasksScrum,
	): array
	{
		$contextUserId = ($contextUserId > 0 ? $contextUserId : $currentUserId);

		$gridRows = $this->scrumService->getList(
			filter: $filter,
			sort: $sort,
			offset: $offset,
			limit: $limit,
			select: $select,
			withImage: $withImage,
			withMembers: $withMembers,
			withOwner: $withOwner,
			currentUserId: $currentUserId,
			contextUserId: $contextUserId,
			isAdmin: $isAdmin,
			pinUserId: $this->getPinUserIdForCurrentContext($currentUserId, $contextUserId),
			pinMode: $pinMode,
		);

		$rows = array_map(static fn($gridRow): array => $gridRow->toArray(), $gridRows);

		$this->hydrateRows(
			rows: $rows,
			currentUserId: $currentUserId,
			contextUserId: $contextUserId,
			isScrum: true,
			pinMode: $pinMode,
		);

		return $rows;
	}

	private function hydrateRows(
		array &$rows,
		int $currentUserId,
		int $contextUserId,
		bool $isScrum,
		WorkgroupPinMode $pinMode,
	): void
	{
		$this->fillAccessData($rows, $currentUserId, $contextUserId, $isScrum);
		$this->fillPinData($rows, $currentUserId, $pinMode);
		$this->fillFavoriteData($rows, $currentUserId);
		$this->fillChatData($rows);
	}

	private function fillProjectEfficiency(array &$rows): void
	{
		$projectIds = array_column($rows, 'ID');
		if (empty($projectIds))
		{
			return;
		}

		$efficiencies = $this->projectEfficiencyService->getEfficiency($projectIds);

		foreach ($rows as &$row)
		{
			$projectId = (int)($row['ID'] ?? 0);
			$row['EFFICIENCY'] = $efficiencies[$projectId] ?? 0;
		}
		unset($row);
	}

	private function fillAccessData(array &$rows, int $currentUserId, int $contextUserId, bool $isScrum): void
	{
		if ($currentUserId <= 0)
		{
			return;
		}

		$ids = array_column($rows, 'ID');
		if (empty($ids))
		{
			return;
		}

		$accessService = $isScrum
			? $this->scrumAccessService
			: $this->legacyGroupAccessService;

		$currentUserRelations = $this->projectMemberRepository->getUserRelations(
			groupIds: $ids,
			userId: $currentUserId,
		);
		$displayRelations = ($contextUserId > 0 && $contextUserId !== $currentUserId)
			? $this->projectMemberRepository->getUserRelations(
				groupIds: $ids,
				userId: $contextUserId,
			)
			: $currentUserRelations
		;

		$showCurrentUserRoleActions = ($contextUserId === $currentUserId);

		foreach ($rows as &$row)
		{
			$id = (int)($row['ID'] ?? 0);
			if ($id <= 0)
			{
				continue;
			}

			$currentUserRelation = $currentUserRelations[$id] ?? null;
			$displayRelation = $displayRelations[$id] ?? null;
			if (
				($displayRelation instanceof WorkgroupUserRelation)
				&& $displayRelation->isMember())
			{
				$roleRelation = $showCurrentUserRoleActions
					? $currentUserRelation
					: $displayRelation;
			}
			else
			{
				$roleRelation = $showCurrentUserRoleActions
					? $currentUserRelation
					: null;
			}
			$isOwner = $currentUserRelation?->role === Role::Owner;

			$row['CURRENT_USER_RELATION'] = $currentUserRelation;
			$row['ROLE_RELATION'] = $roleRelation;
			$row['SHOW_ROLE_ACTIONS'] = $showCurrentUserRoleActions;
			$row['CAN_MODIFY'] = $accessService->canUpdate($currentUserId, $id);
			$row['CAN_DELETE'] = $accessService->canDelete($currentUserId, $id);
			$row['CAN_LEAVE'] = $accessService->canLeave($currentUserId, $id);
			$row['CAN_JOIN'] = $accessService->canJoin($currentUserId, $id);
			$row['CAN_DELETE_INCOMING_REQUEST'] = Access::canDeleteIncomingRequest([
				'groupId' => $id,
				'userId' => $currentUserId,
			]);
			$row['CAN_DELETE_OUTGOING_REQUEST'] = Access::canDeleteOutgoingRequest([
				'groupId' => $id,
				'userId' => $currentUserId,
			]);
		}
		unset($row);
	}

	private function fillPinData(array &$rows, int $currentUserId, WorkgroupPinMode $pinMode): void
	{
		$ids = array_column($rows, 'ID');
		if (empty($ids) || $currentUserId <= 0)
		{
			return;
		}

		$pinnedIds = $this->workgroupPinService
			->getPinFlags(
				groupIds: $ids,
				userId: $currentUserId,
				mode: $pinMode,
			);

		foreach ($rows as &$row)
		{
			$id = (int)($row['ID'] ?? 0);
			$row['IS_PINNED'] = isset($pinnedIds[$id]);
		}
		unset($row);
	}

	private function fillFavoriteData(array &$rows, int $currentUserId): void
	{
		$ids = array_column($rows, 'ID');
		if (empty($ids) || $currentUserId <= 0)
		{
			return;
		}

		$favoriteIds = $this->favoritesRepository->getFavoriteFlags(groupIds: $ids, userId: $currentUserId);

		foreach ($rows as &$row)
		{
			$id = (int)($row['ID'] ?? 0);
			$row['IS_FAVORITE'] = isset($favoriteIds[$id]);
		}
		unset($row);
	}

	private function fillChatData(array &$rows): void
	{
		$ids = array_values(array_filter(array_column($rows, 'ID')));
		if ($ids === [])
		{
			return;
		}

		$projectChatData = $this->projectChatDataProvider->getByProjectIds($ids);

		foreach ($rows as &$row)
		{
			$id = (int)($row['ID'] ?? 0);
			$chatData = $projectChatData[$id] ?? [
				'chatId' => null,
				'color' => null,
			];
			$row['CHAT_ID'] = $chatData['chatId'] ?? null;
			$row['COLOR'] = $chatData['color'] ?? null;
		}
		unset($row);
	}

	private function getPinUserIdForCurrentContext(int $currentUserId, int $contextUserId): ?int
	{
		if ($currentUserId <= 0)
		{
			return null;
		}

		return ($contextUserId === $currentUserId ? $currentUserId : null);
	}
}
