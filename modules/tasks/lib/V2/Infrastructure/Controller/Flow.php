<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Infrastructure\Controller;

use Bitrix\Main\Engine\ActionFilter\Attribute\Rule\CloseSession;
use Bitrix\Tasks\V2\Internal\Access\Flow\Permission;
use Bitrix\Tasks\V2\Internal\Entity;
use Bitrix\Tasks\V2\Public\Provider\Flow\FlowProvider;
use Bitrix\Tasks\V2\Public\Provider\Group\GroupProvider;
use Bitrix\Tasks\V2\Public\Provider\Params\Flow\FlowParams;
use Bitrix\Tasks\V2\Public\Provider\Params\Group\GroupParams;

class Flow extends BaseController
{
	/**
	 * @ajaxAction tasks.V2.Flow.get
	 */
	#[CloseSession]
	public function getAction(
		#[Permission\Read]
		Entity\Flow $flow,
		FlowProvider $flowProvider,
		GroupProvider $groupProvider,
	): ?Entity\Flow
	{
		$flowParams = new FlowParams(
			flowId: $flow->getId(),
			userId: $this->userId,
			checkAccess: false,
		);

		$flow = $flowProvider->get($flowParams);
		if ($flow === null || $flow->group === null)
		{
			return null;
		}

		$groupParams = new GroupParams(
			groupId: $flow->group->getId(),
			userId: $this->userId,
			checkAccess: false,
		);

		$group = $groupProvider->get($groupParams);
		if ($group === null)
		{
			return $flow;
		}

		return $flow->cloneWith(['group' => $group]);
	}
}
