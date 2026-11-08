<?php

namespace Bitrix\Stafftrack\Integration\HumanResources;

use Bitrix\HumanResources\Item\NodeMember;
use Bitrix\HumanResources\Service\Container;
use Bitrix\HumanResources\Type\MemberEntityType;
use Bitrix\Main;
use Bitrix\StaffTrack\Trait\Singleton;
use Bitrix\StaffTrack\Item;

class Structure
{
	use Singleton;

	public function getDepartmentsByUserId(int $userId): Item\Collection\DepartmentCollection
	{
		if (!Main\Loader::includeModule('humanresources'))
		{
			return new Item\Collection\DepartmentCollection([]);
		}

		$departmentNodes = Container::getNodeService()->getNodesByUserId($userId);

		$departments = [];
		foreach ($departmentNodes->getIterator() as $departmentNode)
		{
			$departments[] = new Item\Department(
				id: $departmentNode->id,
				name: $departmentNode->name,
			);
		}

		return new Item\Collection\DepartmentCollection($departments);
	}

	public function getDepartmentUserIds(int $departmentId): array
	{
		if (!Main\Loader::includeModule('humanresources'))
		{
			return [];
		}

		$userNodes = Container::getNodeMemberService()->getAllEmployees($departmentId);

		return array_map(static fn ($entity) => $entity->entityId, [...$userNodes->getIterator()]);
	}

	/**
	 * Returns the subset of $userIds that share at least one department with
	 * the caller. Membership only: no sub-tree expansion and no roster load —
	 * a single batch query resolves the requested users' node membership.
	 * Returns [] when the humanresources module is unavailable.
	 *
	 * @param int $callerId
	 * @param int[] $userIds
	 * @return int[]
	 */
	public function filterUsersInSameDepartments(int $callerId, array $userIds): array
	{
		if ($userIds === [] || !Main\Loader::includeModule('humanresources'))
		{
			return [];
		}

		$callerNodeIds = [];
		foreach ($this->getDepartmentsByUserId($callerId) as $department)
		{
			$callerNodeIds[$department->id] = true;
		}

		if ($callerNodeIds === [])
		{
			return [];
		}

		$userIds = array_values(array_unique(array_map('intval', $userIds)));

		$members = Container::getNodeMemberRepository()->findAllByEntityIdsAndEntityType(
			$userIds,
			MemberEntityType::USER,
		);

		$allowedUserIds = [];
		foreach ($members as $member)
		{
			if (isset($callerNodeIds[$member->nodeId]))
			{
				$allowedUserIds[$member->entityId] = true;
			}
		}

		return array_map('intval', array_keys($allowedUserIds));
	}

	public function getDepartmentHeadId(int $departmentId): int
	{
		if (!Main\Loader::includeModule('humanresources'))
		{
			return 0;
		}

		$headRole = Container::getRoleRepository()->findByXmlId(NodeMember::DEFAULT_ROLE_XML_ID['HEAD'])?->id;

		if ($headRole === null)
		{
			return 0;
		}

		$headMembers = Container::getNodeMemberRepository()->findAllByRoleIdAndNodeId($headRole, $departmentId);

		return $headMembers->getIterator()->current()?->entityId ?? 0;
	}
}
