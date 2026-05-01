<?php

namespace Bitrix\Rest\Internal\Integration\HumanResources;

use Bitrix\HumanResources\Item\Collection\NodeMemberCollection;
use Bitrix\HumanResources\Item\NodeMember;
use Bitrix\HumanResources\Service\Container;
use Bitrix\HumanResources\Type\MemberEntityType;

final class MemberService
{
	public function clone(int $fromMemberId, int $toMemberId): void
	{
		$oldMemberCollection = \Bitrix\HumanResources\Public\Service\Container::getNodeMemberService()->findAllByEntityIds([$fromMemberId]);
		$newMemberCollection = new NodeMemberCollection();
		$defaultRoleId = Container::getRoleHelperService()->getEmployeeRoleId();

		foreach($oldMemberCollection as $nodeMember)
		{
			$newMemberCollection->add(
				new NodeMember(
					entityType: MemberEntityType::USER,
					entityId: $toMemberId,
					nodeId: $nodeMember->nodeId,
					active: true,
					role: $nodeMember->roles[0] ?? $defaultRoleId,
				)
			);
		}

		if (!$newMemberCollection->empty())
		{
			Container::getNodeMemberRepository()->createByCollection($newMemberCollection);
		}
	}
}