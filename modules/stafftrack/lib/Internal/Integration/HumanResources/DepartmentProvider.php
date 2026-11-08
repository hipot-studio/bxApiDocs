<?php
namespace Bitrix\StaffTrack\Internal\Integration\HumanResources;

use Bitrix\HumanResources\Builder\Structure\Filter\Column\IdFilter;
use Bitrix\HumanResources\Builder\Structure\Filter\NodeFilter;
use Bitrix\HumanResources\Builder\Structure\Filter\NodeMemberFilter;
use Bitrix\HumanResources\Builder\Structure\NodeMemberDataBuilder;
use Bitrix\HumanResources\Enum\DepthLevel;
use Bitrix\HumanResources\Public\Service\Container as HrPublicContainer;
use Bitrix\HumanResources\Type\MemberEntityType;
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

	public static function getAllSubordinateIds(int $userId): array
	{
		if (!Loader::includeModule('humanresources'))
		{
			return [];
		}

		return self::getSubordinateResolver()->resolve($userId, false);
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
	 */
	private static function isAccessibleWithinComposition(
		int $viewerUserId,
		int $employeeUserId,
		array $compositionMap,
	): bool
	{
		$guardResult = self::resolveAccessibilityGuard($viewerUserId, $employeeUserId);
		if ($guardResult !== null)
		{
			return $guardResult;
		}

		return isset($compositionMap[$employeeUserId])
			|| self::isManagerForEmployee($viewerUserId, $employeeUserId);
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

		return self::filterManagedUserIds($userIds, $userId);
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

	private static function filterManagedUserIds(array $userIds, int $managerUserId): array
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
		foreach ($userIds as $userId)
		{
			if (self::isAccessibleWithinComposition($managerUserId, $userId, $compositionMap))
			{
				$filteredUserIds[] = $userId;
			}
		}

		return array_slice(IdNormalizer::normalize($filteredUserIds), 0, self::MAX_SELECTOR_USER_IDS);
	}

	private static function getUserIdsByDepartments(array $departmentIds, bool $isFlat, int $limit): array
	{
		$departmentIds = IdNormalizer::normalize($departmentIds);
		if (empty($departmentIds) || $limit <= 0 || !Loader::includeModule('humanresources'))
		{
			return [];
		}

		$userIds = [
			...NodeMemberDataBuilder::createWithFilter(
				new NodeMemberFilter(
					entityType: MemberEntityType::USER,
					nodeFilter: new NodeFilter(
						idFilter: IdFilter::fromIds($departmentIds),
						depthLevel: $isFlat ? DepthLevel::NONE : DepthLevel::FULL,
					),
				),
			)
				->setLimit($limit)
				->getAll()
				->getEntityIds()
		];

		return array_slice(IdNormalizer::normalize($userIds), 0, $limit);
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

	private static function getAdminIds(): array
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
