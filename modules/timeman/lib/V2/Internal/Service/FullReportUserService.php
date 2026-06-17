<?php

declare(strict_types=1);

namespace Bitrix\Timeman\V2\Internal\Service;

use Bitrix\Main\UserTable;
use Bitrix\Main\Type\Collection;
use Bitrix\Timeman\Integration\Humanresources\DepartmentUsersResolver;
use Bitrix\Timeman\Integration\Humanresources\SubordinateAccessUsersResolver;
use Bitrix\Timeman\Model\Security\TaskAccessCodeTable;
use Bitrix\Timeman\Security\UserPermissionsManager;
use Bitrix\Timeman\V2\Internal\Entity\User;
use Bitrix\Timeman\V2\Internal\Entity\UserCollection;
use Bitrix\Timeman\V2\Internal\Integration;

final class FullReportUserService
{
	public function __construct(
		private readonly UserServiceInterface $userService,
	)
	{
		$this->departmentUsersResolver = new DepartmentUsersResolver();
		$this->intranetUserChecker = new Integration\Intranet\UserChecker();
		$this->bitrix24UserChecker = new Integration\Bitrix24\UserChecker();
	}

	/**
	 * @var array<int, array{fromUserId: int, toUserIds: array<int, int>}>
	 */
	private array $participantUserIdsCache = [];

	/**
	 * @var array<int, array<int, int>>
	 */
	private array $accessibleToReadUserIdsCache = [];

	/**
	 * @var array<string, bool>
	 */
	private array $operationAvailabilityCache = [];

	/**
	 * @var ?array<int, int>
	 */
	private ?array $companyUserIdsCache = null;

	private readonly DepartmentUsersResolver $departmentUsersResolver;

	private readonly Integration\Intranet\UserChecker $intranetUserChecker;

	private readonly Integration\Bitrix24\UserChecker $bitrix24UserChecker;

	/**
	 * @param array<int, int> $userIds
	 * @return array<int, array{fromUser: User, toUsers: UserCollection}>
	 */
	public function getParticipantsDataByUserIds(array $userIds): array
	{
		Collection::normalizeArrayValuesByInt($userIds, false);
		$userIds = array_values(array_unique(array_filter(
			$userIds,
			static fn (int $userId): bool => $userId > 0,
		)));

		if (empty($userIds))
		{
			return [];
		}

		$relationsByUserId = [];
		$participantIds = [];

		foreach ($userIds as $userId)
		{
			$relations = $this->getParticipantUserIds($userId);
			$relationsByUserId[$userId] = $relations;

			$participantIds[] = $relations['fromUserId'];
			$participantIds = [...$participantIds, ...$relations['toUserIds']];
		}

		$usersById = $this->indexUsersById($this->userService->getUsers($participantIds));

		$result = [];
		foreach ($relationsByUserId as $userId => $relations)
		{
			$toUsers = [];
			foreach ($relations['toUserIds'] as $toUserId)
			{
				$toUsers[] = $usersById[$toUserId] ?? $this->buildFallbackUser($toUserId);
			}

			$result[$userId] = [
				'fromUser' => (
					$usersById[$relations['fromUserId']]
					?? $this->buildFallbackUser($relations['fromUserId'])
				),
				'toUsers' => new UserCollection(...$toUsers),
			];
		}

		return $result;
	}

	/**
	 * Returns ids of users whose full reports the passed user can read.
	 *
	 * The logic mirrors legacy `CTimeMan::GetAccess()['READ']`, but is resolved
	 * for an explicit user id instead of relying on the current global user.
	 *
	 * @return array<int, int>
	 */
	public function getUserIdsAccessibleToRead(int $userId): array
	{
		if ($userId <= 0)
		{
			return [];
		}

		if (isset($this->accessibleToReadUserIdsCache[$userId]))
		{
			return $this->accessibleToReadUserIdsCache[$userId];
		}

		if ($this->userHasOperation($userId, UserPermissionsManager::OP_READ_WORKTIME_ALL))
		{
			return $this->accessibleToReadUserIdsCache[$userId] = $this->getAllCompanyUserIds();
		}

		if (!$this->userHasOperation($userId, UserPermissionsManager::OP_READ_WORKTIME_SUBORDINATE))
		{
			return $this->accessibleToReadUserIdsCache[$userId] = [];
		}

		$accessSettings = \CTimeMan::GetAccessSettings();
		$employeeReadLevel = (int)($accessSettings['READ']['EMPLOYEE'] ?? 0);
		$headReadLevel = (int)($accessSettings['READ']['HEAD'] ?? 0);

		if ($employeeReadLevel >= 2)
		{
			return $this->accessibleToReadUserIdsCache[$userId] = $this->getAllCompanyUserIds();
		}

		$accessibleUserIds = [$userId];

		if ($employeeReadLevel >= 1)
		{
			$accessibleUserIds = array_merge(
				$accessibleUserIds,
				$this->departmentUsersResolver->getDepartmentUserIds($userId),
			);
		}

		$subordinateUserIds = $this->departmentUsersResolver->getSubordinateDepartmentUserIds(
			$userId,
			$headReadLevel === 1,
		);
		foreach ($subordinateUserIds as $subordinateUserId)
		{
			if ($headReadLevel === 2)
			{
				return $this->accessibleToReadUserIdsCache[$userId] = $this->getAllCompanyUserIds();
			}

			$accessibleUserIds[] = $subordinateUserId;
		}

		$accessibleUserIds = array_merge(
			$accessibleUserIds,
			(new SubordinateAccessUsersResolver())->getSubordinateAccessUsers($userId),
		);

		return $this->accessibleToReadUserIdsCache[$userId] = $this->normalizeUserIds($accessibleUserIds);
	}

	public function getReportApprover(int $userId): int
	{
		$participantUserIds = $this->getParticipantUserIds($userId);
		if (!empty($participantUserIds['toUserIds']))
		{
			return current($participantUserIds['toUserIds']);
		}

		return 0;
	}

	/**
	 * @return array{fromUserId: int, toUserIds: array<int, int>}
	 */
	private function getParticipantUserIds(int $userId): array
	{
		if (isset($this->participantUserIdsCache[$userId]))
		{
			return $this->participantUserIdsCache[$userId];
		}

		$managerIds = array_slice((array)\CTimeMan::getUserManagers($userId), 0, 1);
		Collection::normalizeArrayValuesByInt($managerIds, false);
		$managerIds = array_values(array_filter(
			array_unique($managerIds),
			static fn (int $managerId): bool => $managerId > 0 && $managerId !== $userId,
		));

		if (empty($managerIds))
		{
			$managerIds = [$userId];
		}

		return $this->participantUserIdsCache[$userId] = [
			'fromUserId' => $userId,
			'toUserIds' => $managerIds,
		];
	}

	/**
	 * @return array<int, User>
	 */
	private function indexUsersById(UserCollection $users): array
	{
		$result = [];
		foreach ($users as $user)
		{
			$result[$user->id] = $user;
		}

		return $result;
	}

	private function buildFallbackUser(int $userId): User
	{
		return new User(
			id: $userId,
		);
	}

	private function userHasOperation(int $userId, string $operationName): bool
	{
		if ($this->isUserAdmin($userId))
		{
			return true;
		}

		$cacheKey = $userId . ':' . $operationName;
		if (isset($this->operationAvailabilityCache[$cacheKey]))
		{
			return $this->operationAvailabilityCache[$cacheKey];
		}

		$userAccessCodes = array_values(array_filter(
			\CAccess::GetUserCodesArray($userId),
			static fn (string $code): bool => mb_strpos($code, 'CHAT') !== 0,
		));

		if (empty($userAccessCodes))
		{
			return $this->operationAvailabilityCache[$cacheKey] = false;
		}

		$operationExists = TaskAccessCodeTable::query()
			->addSelect('ACCESS_CODE')
			->whereIn('ACCESS_CODE', $userAccessCodes)
			->where('TASK_OPERATION.OPERATION.NAME', $operationName)
			->where('USER_ACCESS.USER_ID', $userId)
			->setCacheTtl(86400)
			->cacheJoins(true)
			->setLimit(1)
			->fetch()
		;

		return $this->operationAvailabilityCache[$cacheKey] = is_array($operationExists);
	}

	private function isUserAdmin(int $userId): bool
	{
		if (in_array(1, \CUser::GetUserGroup($userId), true))
		{
			return true;
		}

		return $this->bitrix24UserChecker->isPortalAdmin($userId);
	}

	/**
	 * @return array<int, int>
	 */
	private function getAllCompanyUserIds(): array
	{
		if ($this->companyUserIdsCache !== null)
		{
			return $this->companyUserIdsCache;
		}

		$userIds = array_column(
			UserTable::query()
				->setSelect(['ID'])
				->where('ACTIVE', 'Y')
				->exec()
				->fetchAll(),
			'ID',
		);

		$userIds = $this->normalizeUserIds($userIds);

		return $this->companyUserIdsCache = $this->intranetUserChecker->excludeExtranetUserIds($userIds);
	}

	/**
	 * @param array<int, mixed> $userIds
	 * @return array<int, int>
	 */
	private function normalizeUserIds(array $userIds): array
	{
		Collection::normalizeArrayValuesByInt($userIds, false);

		return array_values(array_unique(array_filter(
			$userIds,
			static fn (int $userId): bool => $userId > 0,
		)));
	}
}
