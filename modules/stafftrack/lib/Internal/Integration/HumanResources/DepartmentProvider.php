<?php
namespace Bitrix\StaffTrack\Internal\Integration\HumanResources;

use Bitrix\HumanResources\Public\Service\Container as HrPublicContainer;
use Bitrix\HumanResources\Type\NodeEntityType;
use Bitrix\Main\Loader;
use Bitrix\StaffTrack\Item\Department;
use Bitrix\StaffTrack\Service\UserService;
use Bitrix\StaffTrack\Item\User;

class DepartmentProvider
{
	private const MAX_SELECTOR_USER_IDS = 300;

	private static ?SubordinateResolver $subordinateResolver = null;

	private static ?BranchManagerResolver $branchManagerResolver = null;

	private static ?DepartmentMemberReader $departmentMemberReader = null;

	private static ?ManagedEmployeeResolver $managedEmployeeResolver = null;

	/**
	 * Heads of an employee, looked up once per request: one check-in of theirs asks for them from three
	 * sides at once - the push of the command, the IM message and the map cache dropped once the address
	 * of the point is resolved, which is attempted in the very same request - and the structure of the
	 * company does not change within a request.
	 *
	 * @var array<int, int[]>
	 */
	private static array $departmentHeadsIdsCache = [];

	/** @var array<int, int[]> */
	private static array $managerIdsByEmployeeCache = [];

	public static function getHeadId(int $userId): ?int
	{
		$departmentHeadIds = self::getDepartmentHeadsIdsByUserId($userId);
		$adminIds = self::getAdminIds();

		return $departmentHeadIds[0] ?? $adminIds[0] ?? null;
	}

	public static function getDepartmentIdsByUserId(int $userId): array
	{
		$user = self::getUserById($userId);
		if ($user === null)
		{
			return [];
		}

		return array_map(
			static fn (Department $department) => $department->id,
			$user->departments->getValues(),
		);
	}

	private static function getUserById(int $userId): ?User
	{
		return UserService::getInstance()->getUser($userId);
	}

	public static function getDepartmentHeadsIdsByUserId(int $userId): array
	{
		return self::$departmentHeadsIdsCache[$userId] ??= self::loadDepartmentHeadsIds($userId);
	}

	private static function loadDepartmentHeadsIds(int $userId): array
	{
		if (!Loader::includeModule('humanresources'))
		{
			return [];
		}

		$nodeMemberCollection = HrPublicContainer::getUserDepartmentService()->getUserHeads($userId);

		$departmentHeadsIds = $nodeMemberCollection->getEntityIds();

		return array_reverse($departmentHeadsIds);
	}

	/**
	 * @param int[] $employeeIds
	 * @return int[]
	 */
	public static function getManagerIdsForEmployees(array $employeeIds): array
	{
		$employeeIds = IdNormalizer::normalize($employeeIds);
		if (empty($employeeIds) || !Loader::includeModule('humanresources'))
		{
			return [];
		}

		self::rememberManagerIdsForEmployees($employeeIds);

		$managerIds = [];
		foreach ($employeeIds as $employeeId)
		{
			$managerIds = [...$managerIds, ...(self::$managerIdsByEmployeeCache[$employeeId] ?? [])];
		}

		return IdNormalizer::normalize($managerIds);
	}

	/**
	 * @param int[] $employeeIds
	 */
	private static function rememberManagerIdsForEmployees(array $employeeIds): void
	{
		$unknownEmployeeIds = array_values(
			array_filter(
				$employeeIds,
				static fn (int $employeeId) => !isset(self::$managerIdsByEmployeeCache[$employeeId]),
			),
		);
		if (empty($unknownEmployeeIds))
		{
			return;
		}

		try
		{
			$managerCollections = HrPublicContainer::getUserDepartmentService()
				->getUsersManagers($unknownEmployeeIds, includeDeputies: true)
			;
		}
		catch (\Throwable)
		{
			return;
		}

		foreach ($unknownEmployeeIds as $employeeId)
		{
			$managers = $managerCollections[$employeeId] ?? null;

			self::$managerIdsByEmployeeCache[$employeeId] = $managers === null
				? []
				: IdNormalizer::normalize($managers->getEntityIds());
		}
	}

	/**
	 * @param int[] $employeeIds
	 * @return int[]
	 */
	public static function getBranchManagerIdsForEmployees(array $employeeIds): array
	{
		return self::getBranchManagerResolver()->resolve($employeeIds);
	}

	/**
	 * @param int[] $employeeIds
	 * @return array<int, int[]>
	 */
	public static function getBranchManagerIdsByEmployee(array $employeeIds): array
	{
		return self::getBranchManagerResolver()->resolveByEmployee($employeeIds);
	}

	private static function getBranchManagerResolver(): BranchManagerResolver
	{
		return self::$branchManagerResolver ??= new BranchManagerResolver();
	}

	public static function getSubordinateIds(int $userId): array
	{
		if (!Loader::includeModule('humanresources'))
		{
			return [];
		}

		return self::getSubordinateResolver()->resolve($userId, true);
	}

	/**
	 * Returns the team of the user without a filter: the ids getSubordinateIds() gives, kept between processes until
	 * the structure is reset. Whom the user may see is never decided by it.
	 */
	public static function getTeamScope(int $userId): TeamScope
	{
		if (!Loader::includeModule('humanresources'))
		{
			return new TeamScope([], true, false);
		}

		return self::getSubordinateResolver()->resolveTeam($userId);
	}

	public static function getAllSubordinateIds(int $userId): array
	{
		if (!Loader::includeModule('humanresources'))
		{
			return [];
		}

		return self::getSubordinateResolver()->resolve($userId, false);
	}

	/**
	 * @return int[]
	 */
	public static function getPeerHeadIds(int $userId): array
	{
		if (!Loader::includeModule('humanresources'))
		{
			return [];
		}

		return self::getSubordinateResolver()->resolvePeerHeadIds($userId);
	}

	private static function getSubordinateResolver(): SubordinateResolver
	{
		return self::$subordinateResolver ??= new SubordinateResolver();
	}

	public static function isAccessibleEmployee(int $viewerUserId, int $employeeUserId): bool
	{
		$guardResult = self::resolveAccessibilityGuard($viewerUserId, $employeeUserId);
		if ($guardResult !== null)
		{
			return $guardResult;
		}

		if (self::isManagerForEmployee($viewerUserId, $employeeUserId))
		{
			return true;
		}

		if (!self::isHeadOrDeputyOfDepartment($viewerUserId))
		{
			return false;
		}

		$compositionMap = array_flip(self::getAllSubordinateIds($viewerUserId));

		return isset($compositionMap[$employeeUserId]);
	}

	/**
	 * @param array<int, int> $compositionMap
	 * @param array<int, int> $managedMap
	 */
	private static function isAccessibleWithinComposition(
		int $viewerUserId,
		int $employeeUserId,
		array $compositionMap,
		array $managedMap,
	): bool
	{
		$guardResult = self::resolveAccessibilityGuard($viewerUserId, $employeeUserId);
		if ($guardResult !== null)
		{
			return $guardResult;
		}

		return isset($compositionMap[$employeeUserId]) || isset($managedMap[$employeeUserId]);
	}

	private static function resolveAccessibilityGuard(int $viewerUserId, int $employeeUserId): ?bool
	{
		if ($viewerUserId <= 0 || $employeeUserId <= 0)
		{
			return false;
		}

		if ($viewerUserId === $employeeUserId)
		{
			return true;
		}

		return null;
	}

	public static function isManagerForEmployee(int $managerUserId, int $employeeUserId): bool
	{
		if (!Loader::includeModule('humanresources'))
		{
			return false;
		}

		try
		{
			return HrPublicContainer::getUserService()->isManagerForEmployee(
				$managerUserId,
				$employeeUserId,
				[NodeEntityType::DEPARTMENT],
			);
		}
		catch (\Throwable)
		{
			return false;
		}
	}

	public static function resolveDepartmentSelectorEntities(array $entities): array
	{
		$normalizedEntities = SelectorEntityNormalizer::normalize($entities);

		return self::resolveNormalizedDepartmentSelectorEntities($normalizedEntities, self::MAX_SELECTOR_USER_IDS);
	}

	public static function resolveDepartmentSelectorEntitiesForUser(array $entities, int $userId): array
	{
		if ($userId <= 0)
		{
			return [];
		}

		$normalizedEntities = SelectorEntityNormalizer::normalize($entities);
		$userIds = self::resolveNormalizedDepartmentSelectorEntities($normalizedEntities, self::MAX_SELECTOR_USER_IDS);

		return self::filterManagedUserIds($userIds, $userId, self::MAX_SELECTOR_USER_IDS);
	}

	/**
	 * The same composition without the ceiling of the map, for a caller that may not lose anybody.
	 *
	 * @return int[]|null null when the selector has more codes than the parse reads or names more than $limit
	 *     users
	 */
	public static function resolveCompleteDepartmentSelectorEntitiesForUser(
		array $entities,
		int $userId,
		int $limit,
	): ?array
	{
		if ($userId <= 0)
		{
			return [];
		}

		if (count($entities) > SelectorEntityNormalizer::MAX_SELECTOR_ENTITIES)
		{
			return null;
		}

		[$userIds, $flatDepartmentIds, $recursiveDepartmentIds] = SelectorEntityNormalizer::normalize($entities);

		// each read gets the whole budget: the people it shares with the other one are not known in advance
		$userIds = IdNormalizer::normalize([
			...$userIds,
			...self::getDistinctUserIdsByDepartments($flatDepartmentIds, true, $limit + 1),
			...self::getDistinctUserIdsByDepartments($recursiveDepartmentIds, false, $limit + 1),
		]);
		if (count($userIds) > $limit)
		{
			return null;
		}

		return self::filterManagedUserIds($userIds, $userId, $limit);
	}

	private static function resolveNormalizedDepartmentSelectorEntities(array $entities, int $limit): array
	{
		[$userIds, $flatDepartmentIds, $recursiveDepartmentIds] = $entities;

		$userIds = IdNormalizer::normalize($userIds);
		$remainingLimit = max(0, $limit - count($userIds));
		if ($remainingLimit > 0)
		{
			$userIds = [
				...$userIds,
				...self::getUserIdsByDepartments($flatDepartmentIds, true, $remainingLimit),
			];
		}

		$userIds = IdNormalizer::normalize($userIds);
		$remainingLimit = max(0, $limit - count($userIds));
		if ($remainingLimit > 0)
		{
			$userIds = [
				...$userIds,
				...self::getUserIdsByDepartments($recursiveDepartmentIds, false, $remainingLimit),
			];
		}

		return array_slice(IdNormalizer::normalize($userIds), 0, $limit);
	}

	private static function filterManagedUserIds(array $userIds, int $managerUserId, int $limit): array
	{
		$userIds = IdNormalizer::normalize($userIds);
		if (empty($userIds))
		{
			return [];
		}

		if (!Loader::includeModule('humanresources'))
		{
			return in_array($managerUserId, $userIds, true) ? [$managerUserId] : [];
		}

		$filteredUserIds = [];
		$compositionMap = array_flip(self::getAllSubordinateIds($managerUserId));
		// one batch: the manager check per employee costs a query each, and a selector holds up to 1500 people
		$managedMap = array_flip(
			self::getManagedEmployeeResolver()->resolve(
				$managerUserId,
				array_filter(
					$userIds,
					static fn (int $userId): bool => $userId !== $managerUserId && !isset($compositionMap[$userId]),
				),
			),
		);
		foreach ($userIds as $userId)
		{
			if (self::isAccessibleWithinComposition($managerUserId, $userId, $compositionMap, $managedMap))
			{
				$filteredUserIds[] = $userId;
			}
		}

		return array_slice(IdNormalizer::normalize($filteredUserIds), 0, $limit);
	}

	private static function getManagedEmployeeResolver(): ManagedEmployeeResolver
	{
		return self::$managedEmployeeResolver ??= new ManagedEmployeeResolver();
	}

	private static function getUserIdsByDepartments(array $departmentIds, bool $isFlat, int $limit): array
	{
		$departmentIds = IdNormalizer::normalize($departmentIds);
		if (empty($departmentIds) || $limit <= 0 || !Loader::includeModule('humanresources'))
		{
			return [];
		}

		$userIds = self::getDepartmentMemberReader()->readUserIds($departmentIds, $isFlat, $limit);

		return array_slice(IdNormalizer::normalize($userIds), 0, $limit);
	}

	/**
	 * @return int[] at most $limit people, each once
	 */
	private static function getDistinctUserIdsByDepartments(array $departmentIds, bool $isFlat, int $limit): array
	{
		$departmentIds = IdNormalizer::normalize($departmentIds);
		if (empty($departmentIds) || !Loader::includeModule('humanresources'))
		{
			return [];
		}

		return self::getDepartmentMemberReader()->readDistinctUserIds($departmentIds, $isFlat, $limit);
	}

	private static function getDepartmentMemberReader(): DepartmentMemberReader
	{
		return self::$departmentMemberReader ??= new DepartmentMemberReader();
	}

	/**
	 * IDs of DEPARTMENT nodes where the user is head or deputy head (no subtree).
	 *
	 * @return int[]
	 */
	public static function getManagedDepartmentIds(int $userId): array
	{
		if (!Loader::includeModule('humanresources'))
		{
			return [];
		}

		return HrPublicContainer::getUserDepartmentService()
			->getNodeMembersWhereUserIsHeadOrDeputy($userId)
			->getNodeIds()
		;
	}

	public static function isHeadOrDeputyOfDepartment(int $userId): bool
	{
		if (!Loader::includeModule('humanresources'))
		{
			return false;
		}

		return HrPublicContainer::getUserDepartmentService()->isHeadOrDeputyOfDepartment($userId);
	}

	/**
	 * Administrators of the portal.
	 *
	 * @return int[]
	 */
	public static function getAdminIds(): array
	{
		$adminIds = [];
		if (Loader::includeModule('bitrix24'))
		{
			$adminIds = \CBitrix24::getAllAdminId();
		}
		else
		{
			$result = \CGroup::GetGroupUserEx(1);
			while ($row = $result->fetch())
			{
				$adminIds[] = (int)$row['USER_ID'];
			}
		}

		return $adminIds;
	}
}
