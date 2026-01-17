<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Infrastructure\Controller\Group;

use Bitrix\Main\Engine\ActionFilter\Attribute\Rule\CloseSession;
use Bitrix\Tasks\V2\Infrastructure\Controller\BaseController;
use Bitrix\Tasks\V2\Internal\DI\Container;
use Bitrix\Tasks\V2\Internal\Entity;
use Bitrix\Tasks\V2\Internal\Access\Group\Permission;
use Bitrix\Tasks\V2\Public\Provider\Params\Stage\StageParams;
use Bitrix\Tasks\V2\Public\Provider\StageProvider;

class Stage extends BaseController
{
	/**
	 * @ajaxAction tasks.V2.Group.Stage.list
	 */
	#[CloseSession]
	public function listAction(
		#[Permission\Read]
		Entity\Group $group,
		StageProvider $stageProvider
	): ?Entity\StageCollection
	{
		return $stageProvider->getByGroupId(
			new StageParams(
				groupId: $group->getId(),
				userId: $this->userId,
				checkAccess: false,
			)
		);
	}
}
