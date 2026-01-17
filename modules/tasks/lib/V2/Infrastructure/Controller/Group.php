<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Infrastructure\Controller;

use Bitrix\Main\Engine\ActionFilter\Attribute\Rule\CloseSession;
use Bitrix\Tasks\V2\Internal\Access\Group\Permission;
use Bitrix\Tasks\V2\Internal\Entity;
use Bitrix\Tasks\V2\Public\Provider\Group\GroupProvider;
use Bitrix\Tasks\V2\Public\Provider\Params\Group\GroupParams;

class Group extends BaseController
{
	/**
	 * @ajaxAction tasks.V2.Group.get
	 */
	#[CloseSession]
	public function getAction(
		#[Permission\Read]
		Entity\Group $group,
		GroupProvider $groupProvider,
	): ?Entity\Group
	{
		$groupParams = new GroupParams(
			groupId: (int)$group->getId(),
			userId: $this->userId,
			checkAccess: false,
		);

		return $groupProvider->get($groupParams);
	}
}
