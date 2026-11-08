<?php

namespace Bitrix\Mobile\Tab\Preset\Service;

use Bitrix\HumanResources\Compatibility\Utils\DepartmentBackwardAccessCode;
use Bitrix\HumanResources\Enum\DepthLevel;
use Bitrix\HumanResources\Public\Service\Container as HumanResourcesContainer;
use Bitrix\HumanResources\Service\Container as HumanResourcesServiceContainer;
use Bitrix\HumanResources\Type\MemberEntityType;
use Bitrix\HumanResources\Type\NodeEntityType;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Loader;
use Bitrix\Mobile\Tab\Preset\Enum\AssignmentTargetType;

final class PresetAccessPolicy
{
	private readonly \Closure $currentSystemAdminChecker;
	private ?array $headDepartmentIdsByUser = null;
	private ?array $assignmentDepartmentIdsByUser = null;
	private ?array $assignmentDepartmentIdsByDepartment = null;
	private ?bool $humanResourcesAvailable = null;
	private ?array $adminUserIds = null;

	public function __construct(?\Closure $currentSystemAdminChecker = null)
	{
		$this->currentSystemAdminChecker = $currentSystemAdminChecker ?? static function (int $userId): bool {
			$currentUser = CurrentUser::get();

			return (int)$currentUser->getId() === $userId && $currentUser->isAdmin();
		};
	}

	public function isHumanResourcesAvailable(): bool
	{
		if ($this->humanResourcesAvailable !== null)
		{
			return $this->humanResourcesAvailable;
		}

		if (!Loader::includeModule('humanresources') || !class_exists(HumanResourcesContainer::class))
		{
			$this->humanResourcesAvailable = false;

			return $this->humanResourcesAvailable;
		}

		try
		{
			$this->humanResourcesAvailable = HumanResourcesContainer::getUserDepartmentService() !== null
				&& HumanResourcesContainer::getNodeMemberService() !== null
				&& HumanResourcesContainer::getNodeService() !== null
			;
		}
		catch (\Throwable)
		{
			$this->humanResourcesAvailable = false;
		}

		return $this->humanResourcesAvailable;
	}

	public function canManagePresets(int $userId): bool
	{
		return $this->isAdmin($userId) || !empty($this->getHeadDepartmentIds($userId));
	}

	public function canSetPortalDefault(int $userId): bool
	{
		return ($this->currentSystemAdminChecker)($userId) || $this->isAdmin($userId);
	}

	public function canAssignToTarget(int $assignerId, AssignmentTargetType $targetType, int $targetId): bool
	{
		return match ($targetType)
		{
			AssignmentTargetType::User => $this->canAssignToUser($assignerId, $targetId),
			AssignmentTargetType::HrNode,
			AssignmentTargetType::HrNodeFlat => $this->canAssignToDepartment($assignerId, $targetId),
		};
	}

	public function canAssignToDepartment(int $assignerId, int $departmentId): bool
	{
		if ($departmentId <= 0)
		{
			return false;
		}

		if ($this->isAdmin($assignerId))
		{
			return $this->departmentExists($departmentId);
		}

		$headDepartmentIds = $this->getHeadDepartmentIds($assignerId);
		if (empty($headDepartmentIds))
		{
			return false;
		}

		return !empty(array_intersect($headDepartmentIds, $this->getDepartmentIdsForAssignmentLookupByDepartment($departmentId)));
	}

	public function resolveDepartmentIdByLegacyId(int $legacyDepartmentId): int
	{
		if ($legacyDepartmentId <= 0 || !$this->isHumanResourcesAvailable())
		{
			return 0;
		}

		try
		{
			$node = HumanResourcesContainer::getNodeService()->getByAccessCode(
				DepartmentBackwardAccessCode::makeById($legacyDepartmentId),
			);
		}
		catch (\Throwable)
		{
			return 0;
		}

		return (int)($node?->id ?? 0);
	}

	public function canAssignToUser(int $assignerId, int $targetUserId): bool
	{
		if ($assignerId <= 0 || $targetUserId <= 0)
		{
			return false;
		}

		if ($this->isAdmin($assignerId))
		{
			return true;
		}

		$headDepartmentIds = $this->getHeadDepartmentIds($assignerId);
		if (empty($headDepartmentIds))
		{
			return false;
		}

		if ($assignerId === $targetUserId)
		{
			return true;
		}

		$userDepartmentIds = $this->getDepartmentIdsForAssignmentLookup($targetUserId);

		return !empty(array_intersect($headDepartmentIds, $userDepartmentIds));
	}

	/**
	 * @return int[]
	 */
	public function getHeadDepartmentIds(int $userId): array
	{
		if ($userId <= 0 || !$this->isHumanResourcesAvailable())
		{
			return [];
		}

		$this->headDepartmentIdsByUser ??= [];
		if (!array_key_exists($userId, $this->headDepartmentIdsByUser))
		{
			try
			{
				$departmentIds = HumanResourcesContainer::getUserDepartmentService()
					->getDepartmentIdsWhereUserIsHead($userId)
				;
			}
			catch (\Throwable)
			{
				$departmentIds = [];
			}

			$this->headDepartmentIdsByUser[$userId] = $this->normalizeIds($departmentIds);
		}

		return $this->headDepartmentIdsByUser[$userId];
	}

	/**
	 * @return int[]
	 */
	public function getDirectDepartmentIdsForUser(int $userId): array
	{
		if ($userId <= 0 || !$this->isHumanResourcesAvailable())
		{
			return [];
		}

		try
		{
			$members = HumanResourcesContainer::getNodeMemberService()->findAllByEntityIds(
				entityIds: [$userId],
				memberEntityType: MemberEntityType::USER,
				nodeTypes: [NodeEntityType::DEPARTMENT],
			);
		}
		catch (\Throwable)
		{
			return [];
		}

		return $this->normalizeIds($members->getUniqueNodeIds());
	}

	/**
	 * @return int[]
	 */
	public function getDepartmentIdsForAssignmentLookup(int $userId): array
	{
		if ($userId <= 0 || !$this->isHumanResourcesAvailable())
		{
			return [];
		}

		$this->assignmentDepartmentIdsByUser ??= [];
		if (array_key_exists($userId, $this->assignmentDepartmentIdsByUser))
		{
			return $this->assignmentDepartmentIdsByUser[$userId];
		}

		$departmentIds = $this->getDirectDepartmentIdsForUser($userId);
		foreach ($departmentIds as $departmentId)
		{
			try
			{
				$parents = HumanResourcesContainer::getNodeService()->findParentsByNodeId(
					nodeId: $departmentId,
					nodeTypes: [NodeEntityType::DEPARTMENT],
					depthLevel: DepthLevel::FULL,
				);
			}
			catch (\Throwable)
			{
				continue;
			}

			$departmentIds = array_merge($departmentIds, $parents?->getIds() ?? []);
		}

		$this->assignmentDepartmentIdsByUser[$userId] = $this->normalizeIds($departmentIds);

		return $this->assignmentDepartmentIdsByUser[$userId];
	}

	/**
	 * @return int[]
	 */
	private function getDepartmentIdsForAssignmentLookupByDepartment(int $departmentId): array
	{
		if ($departmentId <= 0 || !$this->isHumanResourcesAvailable())
		{
			return [];
		}

		$this->assignmentDepartmentIdsByDepartment ??= [];
		if (array_key_exists($departmentId, $this->assignmentDepartmentIdsByDepartment))
		{
			return $this->assignmentDepartmentIdsByDepartment[$departmentId];
		}

		$departmentIds = [$departmentId];
		try
		{
			$parents = HumanResourcesContainer::getNodeService()->findParentsByNodeId(
				nodeId: $departmentId,
				nodeTypes: [NodeEntityType::DEPARTMENT],
				depthLevel: DepthLevel::FULL,
			);
			$departmentIds = array_merge($departmentIds, $parents?->getIds() ?? []);
		}
		catch (\Throwable)
		{
		}

		$this->assignmentDepartmentIdsByDepartment[$departmentId] = $this->normalizeIds($departmentIds);

		return $this->assignmentDepartmentIdsByDepartment[$departmentId];
	}

	/**
	 * @return int[]
	 */
	public function getAssignableUserIds(int $assignerId): array
	{
		if (!$this->isHumanResourcesAvailable())
		{
			return [];
		}

		try
		{
			$userIds = HumanResourcesContainer::getUserDepartmentService()->getSubordinateUserIds($assignerId, true);
		}
		catch (\Throwable)
		{
			$userIds = [];
		}

		$userIds[] = $assignerId;
		$userIds = $this->normalizeIds($userIds);

		return array_values(array_filter(
			$userIds,
			fn(int $userId): bool => $this->canAssignToUser($assignerId, $userId),
			));
	}

	/**
	 * @return int[]
	 */
	public function getAssignableUserIdsByDepartment(
		int $assignerId,
		int $departmentId,
		bool $includeChildDepartments = false,
	): array
	{
		if ($departmentId <= 0 || !$this->canAssignToDepartment($assignerId, $departmentId))
		{
			return [];
		}

		if ($this->isAdmin($assignerId))
		{
			return $this->getUserIdsByDepartment($departmentId, $includeChildDepartments);
		}

		return array_values(array_filter(
			$this->getAssignableUserIds($assignerId),
			fn(int $userId): bool => in_array(
				$departmentId,
				$includeChildDepartments
					? $this->getDepartmentIdsForAssignmentLookup($userId)
					: $this->getDirectDepartmentIdsForUser($userId),
				true,
			),
		));
	}

	/**
	 * @return int[]
	 */
	private function getUserIdsByDepartment(int $departmentId, bool $includeChildDepartments = false): array
	{
		if ($departmentId <= 0 || !$this->isHumanResourcesAvailable())
		{
			return [];
		}

		try
		{
			$members = HumanResourcesServiceContainer::getNodeMemberService()->getAllEmployees(
				$departmentId,
				$includeChildDepartments,
			);
		}
		catch (\Throwable)
		{
			return [];
		}

		return $this->normalizeIds($members->getUniqueEntityIds());
	}

	private function departmentExists(int $departmentId): bool
	{
		if ($departmentId <= 0 || !$this->isHumanResourcesAvailable())
		{
			return false;
		}

		try
		{
			return HumanResourcesContainer::getNodeService()->getById($departmentId) !== null;
		}
		catch (\Throwable)
		{
			return false;
		}
	}

	public function getLegacyDepartmentId(int $departmentId): ?int
	{
		if ($departmentId <= 0 || !$this->isHumanResourcesAvailable())
		{
			return null;
		}

		try
		{
			$node = HumanResourcesContainer::getNodeService()->getById($departmentId);
		}
		catch (\Throwable)
		{
			return null;
		}

		return DepartmentBackwardAccessCode::extractIdFromCode($node?->accessCode);
	}

	private function isAdmin(int $userId): bool
	{
		if ($userId <= 0)
		{
			return false;
		}

		$this->adminUserIds ??= $this->loadAdminUserIds();

		return isset($this->adminUserIds[$userId]);
	}

	private function loadAdminUserIds(): array
	{
		$adminIds = [];

		$result = \CGroup::GetGroupUserEx(1);
		while ($row = $result->fetch())
		{
			$adminIds[(int)$row['USER_ID']] = true;
		}

		return $adminIds;
	}

	private function normalizeIds(array $ids): array
	{
		$ids = array_map('intval', $ids);
		$ids = array_filter($ids, static fn(int $id): bool => $id > 0);

		return array_values(array_unique($ids));
	}
}
