<?php

namespace Bitrix\Tasks\Flow\Control\Observer\Trait;

use Bitrix\Tasks\Flow\Integration\Socialnetwork\MemberService;

trait AddUsersToGroupTrait
{
	protected function addUsersToGroup(int $groupId, array $responsibleUsers, int $initiatorId = 0): void
	{
		(new MemberService())->addMembers($groupId, $responsibleUsers, $initiatorId);
	}
}
