<?php

namespace Bitrix\Mail\Integration\HumanResources;

use Bitrix\HumanResources\Builder\Structure\Filter\Column\EntityIdFilter;
use Bitrix\HumanResources\Builder\Structure\Filter\Column\IdFilter;
use Bitrix\HumanResources\Builder\Structure\Filter\Column\Node\NodeTypeFilter;
use Bitrix\HumanResources\Builder\Structure\Filter\NodeFilter;
use Bitrix\HumanResources\Builder\Structure\Filter\NodeMemberFilter;
use Bitrix\HumanResources\Builder\Structure\Filter\SelectionCondition\Node\NodeAccessFilter;
use Bitrix\HumanResources\Builder\Structure\NodeMemberDataBuilder;
use Bitrix\HumanResources\Enum\DepthLevel;
use Bitrix\HumanResources\Service\Container;
use Bitrix\HumanResources\Type\NodeEntityType;
use Bitrix\HumanResources\Type\StructureAction;
use Bitrix\Main\Loader;

class NodeMemberService
{
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
