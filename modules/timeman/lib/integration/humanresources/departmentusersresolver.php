<?php

declare(strict_types=1);

namespace Bitrix\Timeman\Integration\Humanresources;

use Bitrix\HumanResources\Builder\Structure\Filter\Column\EntityIdFilter;
use Bitrix\HumanResources\Builder\Structure\Filter\Column\Node\NodeTypeFilter;
use Bitrix\HumanResources\Builder\Structure\Filter\NodeFilter;
use Bitrix\HumanResources\Builder\Structure\Filter\NodeMemberFilter;
use Bitrix\HumanResources\Builder\Structure\NodeMemberDataBuilder;
use Bitrix\HumanResources\Service\Container;
use Bitrix\HumanResources\Type\MemberEntityType;
use Bitrix\HumanResources\Type\NodeEntityType;
use Bitrix\HumanResources\Type\StructureRole;
use Bitrix\Main\Loader;

final class DepartmentUsersResolver
{
	/** @var array<string, list<int>> */
	private static array $cache = [];

	private SubordinateAccessUsersLogic $logic;

	public function __construct(?SubordinateAccessUsersLogic $logic = null)
	{
		$this->logic = $logic ?? new SubordinateAccessUsersLogic();
	}

	/**
	 * Returns active users from the same department nodes as the given user.
	 *
	 * @return list<int>
	 */
	public function getDepartmentUserIds(int $userId): array
	{
		return $this->resolveDepartmentUsers($userId, 'department-members', false);
	}

	/**
	 * Returns active users from department nodes managed by the given user.
	 *
	 * @return list<int>
	 */
	public function getSubordinateDepartmentUserIds(int $userId, bool $recursive): array
	{
		return $this->resolveDepartmentUsers(
			$userId,
			$recursive ? 'managed-departments-recursive' : 'managed-departments-direct',
			$recursive,
		);
	}

	/**
	 * @return list<int>
	 */
	private function resolveDepartmentUsers(int $userId, string $cacheKeySuffix, bool $withChildNodes): array
	{
		if ($userId <= 0)
		{
			return [];
		}

		$cacheKey = $userId . ':' . $cacheKeySuffix;
		if (isset(self::$cache[$cacheKey]))
		{
			return self::$cache[$cacheKey];
		}

		if (!Loader::includeModule('humanresources'))
		{
			return self::$cache[$cacheKey] = $this->resolveLegacyDepartmentUsers($userId, $cacheKeySuffix, $withChildNodes);
		}

		try
		{
			$nodeIds = ($cacheKeySuffix === 'department-members')
				? $this->getUserDepartmentNodeIds($userId)
				: $this->getManagedDepartmentNodeIds($userId);

			if (empty($nodeIds))
			{
				return self::$cache[$cacheKey] = [];
			}

			$userIds = [];
			$nodeMemberService = Container::getNodeMemberService();
			foreach ($nodeIds as $nodeId)
			{
				foreach ($nodeMemberService->getAllEmployees($nodeId, $withChildNodes, true) as $member)
				{
					$userIds[] = (int)$member->entityId;
				}
			}

			$userIds = $this->normalizeUserIds($userIds);

			if ($cacheKeySuffix !== 'department-members')
			{
				$userIds = $this->logic->filterMemberIds($userIds, $userId);
			}

			return self::$cache[$cacheKey] = $userIds;
		}
		catch (\Throwable)
		{
			return self::$cache[$cacheKey] = [];
		}
	}

	/**
	 * @return list<int>
	 */
	private function resolveLegacyDepartmentUsers(int $userId, string $cacheKeySuffix, bool $withChildNodes): array
	{
		$userIds = [];

		if ($cacheKeySuffix === 'department-members')
		{
			$dbUsers = \CIntranetUtils::GetDepartmentColleagues($userId, false, false, 'Y', ['ID']);
		}
		else
		{
			$departments = \CIntranetUtils::GetSubordinateDepartments($userId, $withChildNodes);
			$dbUsers = \CIntranetUtils::getDepartmentEmployees($departments, false, false, 'Y', ['ID']);
		}

		while ($user = $dbUsers->Fetch())
		{
			$userIds[] = (int)($user['ID'] ?? 0);
		}

		$userIds = $this->normalizeUserIds($userIds);

		if ($cacheKeySuffix !== 'department-members')
		{
			$userIds = $this->logic->filterMemberIds($userIds, $userId);
		}

		return $userIds;
	}

	/**
	 * @return list<int>
	 */
	private function getUserDepartmentNodeIds(int $userId): array
	{
		$memberships = (new NodeMemberDataBuilder())
			->addFilter(
				new NodeMemberFilter(
					entityIdFilter: EntityIdFilter::fromEntityId($userId),
					entityType: MemberEntityType::USER,
					nodeFilter: new NodeFilter(
						entityTypeFilter: NodeTypeFilter::fromNodeType(NodeEntityType::DEPARTMENT),
					),
					findRelatedMembers: false,
				),
			)
			->getAll()
		;

		return $this->normalizeUserIds($memberships->getNodeIds());
	}

	/**
	 * @return list<int>
	 */
	private function getManagedDepartmentNodeIds(int $userId): array
	{
		$memberships = (new NodeMemberDataBuilder())
			->addFilter(
				new NodeMemberFilter(
					entityIdFilter: EntityIdFilter::fromEntityId($userId),
					entityType: MemberEntityType::USER,
					nodeFilter: new NodeFilter(
						entityTypeFilter: NodeTypeFilter::fromNodeType(NodeEntityType::DEPARTMENT),
					),
					findRelatedMembers: false,
				),
			)
			->setStructureRoles([StructureRole::HEAD])
			->getAll()
		;

		return $this->normalizeUserIds($memberships->getNodeIds());
	}

	/**
	 * @param array<int, mixed> $ids
	 * @return list<int>
	 */
	private function normalizeUserIds(array $ids): array
	{
		$ids = array_map('intval', $ids);
		$ids = array_values(array_unique(array_filter(
			$ids,
			static fn (int $id): bool => $id > 0,
		)));

		return $ids;
	}
}
