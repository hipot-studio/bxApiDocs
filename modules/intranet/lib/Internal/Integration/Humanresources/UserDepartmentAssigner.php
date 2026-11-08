<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Integration\Humanresources;

use Bitrix\HumanResources\Enum\NodeActiveFilter;
use Bitrix\HumanResources\Public\Service\Container;
use Bitrix\HumanResources\Service\Container as HrContainer;
use Bitrix\HumanResources\Type\MemberEntityType;
use Bitrix\HumanResources\Type\NodeEntityType;
use Bitrix\Main\Loader;
use Bitrix\Main\LoaderException;
use Bitrix\Main\SystemException;

final class UserDepartmentAssigner
{
	/**
	 * @throws LoaderException
	 * @throws SystemException
	 */
	public function __construct()
	{
		if (!Loader::includeModule('humanresources'))
		{
			throw new SystemException('Module "humanresources" is not installed');
		}
	}

	public function reassignToDepartments(int $userId, array $departmentIds): void
	{
		if ($userId <= 0 || empty($departmentIds))
		{
			return;
		}

		Container::getUserDepartmentService()->assignToDepartments(
			userId: $userId,
			departmentIds: $departmentIds,
			replaceExisting: true,
		);
	}

	public function removeAssignedDepartments(int $userId, array $departmentIds): void
	{
		$repository = HrContainer::getNodeMemberRepository();
		$members = $repository->findAllByEntityIdAndEntityTypeAndNodeType(
			entityId: $userId,
			entityType: MemberEntityType::USER,
			nodeType: NodeEntityType::DEPARTMENT,
			activeFilter: NodeActiveFilter::ALL,
		);
		$assignedMembers = $members->filter(
			static fn ($member) => in_array($member->nodeId, $departmentIds, true),
		);
		if (!$assignedMembers->empty() && !$repository->removeByCollection($assignedMembers))
		{
			throw new \RuntimeException('Failed to remove assigned departments');
		}
		$remainingMembers = $repository->findAllByEntityIdAndEntityTypeAndNodeType(
			entityId: $userId,
			entityType: MemberEntityType::USER,
			nodeType: NodeEntityType::DEPARTMENT,
			activeFilter: NodeActiveFilter::ALL,
		);
		foreach ($remainingMembers as $member)
		{
			if (in_array($member->nodeId, $departmentIds, true))
			{
				throw new \RuntimeException('Assigned departments remain after removal');
			}
		}
	}
}
