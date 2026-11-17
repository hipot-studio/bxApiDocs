<?php

namespace Bitrix\Mail\Integration\HumanResources;

use Bitrix\HumanResources\Builder\Structure\Filter\Column\EntityIdFilter;
use Bitrix\HumanResources\Builder\Structure\Filter\Column\IdFilter;
use Bitrix\HumanResources\Builder\Structure\Filter\Column\Node\NodeTypeFilter;
use Bitrix\HumanResources\Builder\Structure\Filter\NodeFilter;
use Bitrix\HumanResources\Builder\Structure\Filter\NodeMemberFilter;
use Bitrix\HumanResources\Builder\Structure\Filter\SelectionCondition\Node\NodeAccessFilter;
use Bitrix\HumanResources\Builder\Structure\NodeMemberDataBuilder;
use Bitrix\HumanResources\Contract\Service\NodeMemberService as NodeMemberServiceContract;
use Bitrix\HumanResources\Enum\DepthLevel;
use Bitrix\HumanResources\Service\Container;
use Bitrix\HumanResources\Type\NodeEntityType;
use Bitrix\HumanResources\Type\StructureAction;
use Bitrix\Main\Loader;

class NodeMemberService
{
	private const PAGE_SIZE = 500;

	/**
	 * @param $departmentIds array<int>}
	 *
	 * @return array<array{
	 *     id: int,
	 *     name: string,
	 *     avatar: string,
	 * }>
	 * @throws \Bitrix\HumanResources\Exception\WrongStructureItemException
	 * @throws \Bitrix\Main\ArgumentException
	 * @throws \Bitrix\Main\LoaderException
	 * @throws \Bitrix\Main\ObjectPropertyException
	 * @throws \Bitrix\Main\SystemException
	 */
	public static function getMembersByDepartmentIds(array $departmentIds): array
	{
		if (!Loader::includeModule('humanresources'))
		{
			return [];
		}

		$members = (new NodeMemberDataBuilder())
			->addFilter(
				new NodeMemberFilter(
					nodeFilter: new NodeFilter(
						idFilter: IdFilter::fromIds(array_map('intval', $departmentIds)),
						entityTypeFilter: NodeTypeFilter::fromNodeType(NodeEntityType::DEPARTMENT),
						depthLevel: 0,
					),
				),
			)
			->getAll()
		;

		$employeeUserCollection = Container::getUserService()->getUserCollectionFromMemberCollection($members);
		$employeeUsers = [];
		foreach ($employeeUserCollection as $user)
		{
			$employeeUsers[] = [
				'id' => $user->id,
				'name' => Container::getUserService()->getUserName($user),
				'avatar' => Container::getUserService()->getUserAvatar($user, 45),
			];
		}

		return $employeeUsers;
	}

	/**
	 * Returns member user IDs of the given departments (flat, no sub-departments), without name/avatar.
	 *
	 * @param int[] $departmentIds
	 * @return int[]
	 */
	public static function getMemberIdsByDepartmentIds(array $departmentIds): array
	{
		if (!Loader::includeModule('humanresources'))
		{
			return [];
		}

		$members = (new NodeMemberDataBuilder())
			->addFilter(
				new NodeMemberFilter(
					nodeFilter: new NodeFilter(
						idFilter: IdFilter::fromIds(array_map('intval', $departmentIds)),
						entityTypeFilter: NodeTypeFilter::fromNodeType(NodeEntityType::DEPARTMENT),
						depthLevel: 0,
					),
				),
			)
			->getAll()
		;

		return array_values(array_unique($members->getEntityIds()));
	}

	/**
	 * @param int[] $departmentIds
	 * @return int[]
	 */
	public static function getMembersWithSubDepartments(array $departmentIds): array
	{
		if (!Loader::includeModule('humanresources'))
		{
			return [];
		}

		$members = (new NodeMemberDataBuilder())
			->addFilter(
				new NodeMemberFilter(
					nodeFilter: new NodeFilter(
						idFilter: IdFilter::fromIds(array_map('intval', $departmentIds)),
						entityTypeFilter: NodeTypeFilter::fromNodeType(NodeEntityType::DEPARTMENT),
						depthLevel: DepthLevel::FULL,
					),
				),
			)
			->getAll()
		;

		return array_values(array_unique($members->getEntityIds()));
	}

	/**
	 * @param int[] $departmentIds
	 * @return \Generator<int>
	 */
	public static function getPagedMemberIdsByDepartmentIds(
		array $departmentIds,
		bool $withSubDepartments = false,
	): \Generator
	{
		if (!Loader::includeModule('humanresources'))
		{
			return;
		}

		yield from self::iteratePagedMemberIds(
			Container::getNodeMemberService(),
			array_values(array_unique(array_map('intval', $departmentIds))),
			$withSubDepartments,
			self::PAGE_SIZE,
		);
	}

	/**
	 * @param int[] $departmentIds
	 * @return \Generator<int>
	 */
	private static function iteratePagedMemberIds(
		NodeMemberServiceContract $nodeMemberService,
		array $departmentIds,
		bool $withSubDepartments,
		int $pageSize,
	): \Generator
	{
		foreach ($departmentIds as $departmentId)
		{
			$offset = 0;
			do
			{
				$members = $nodeMemberService->getPagedEmployees(
					$departmentId,
					$withSubDepartments,
					$offset,
					$pageSize,
					true,
				);

				foreach ($members->getEntityIds() as $userId)
				{
					yield (int)$userId;
				}

				$memberCount = $members->count();
				$offset += $pageSize;
			}
			while ($memberCount === $pageSize);
		}
	}

	public static function filterUsersByDepartmentIds(
		array $userIds,
		array $departmentIds,
		bool $withSubDepartments = false,
		bool $withCheckViewHrAccess = true,
	): array
	{
		$userIds = array_map('intval', $userIds);
		$departmentIds = array_map('intval', $departmentIds);

		if (
			!Loader::includeModule('humanresources')
			|| empty($userIds)
			|| empty($departmentIds)
		)
		{
			return [];
		}

		$accessFilter = $withCheckViewHrAccess ? new NodeAccessFilter(StructureAction::ViewAction) : null;
		$depthLevel = $withSubDepartments ? DepthLevel::FULL : 0;
		$nodeMembers = (new NodeMemberDataBuilder())
			->addFilter(
				new NodeMemberFilter(
					entityIdFilter: EntityIdFilter::fromEntityIds($userIds),
					nodeFilter: new NodeFilter(
						idFilter: IdFilter::fromIds(array_map('intval', $departmentIds)),
						entityTypeFilter: NodeTypeFilter::fromNodeType(NodeEntityType::DEPARTMENT),
						depthLevel: $depthLevel,
						accessFilter: $accessFilter,
					),
				),
			)
			->getAll()
		;

		return array_unique($nodeMembers->getEntityIds());
	}
}

