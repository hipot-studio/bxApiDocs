<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Public\Provider;

use Bitrix\Tasks\V2\Internal\DI\Container;
use Bitrix\Tasks\V2\Internal\Entity\Group;
use Bitrix\Tasks\V2\Internal\Entity\StageCollection;
use Bitrix\Tasks\V2\Internal\Integration\Socialnetwork\Service\GroupAccessService;
use Bitrix\Tasks\V2\Internal\Service\Task\TaskStageService;
use Bitrix\Tasks\V2\Public\Provider\Params\Stage\StageParams;

class StageProvider
{
	private readonly TaskStageService $taskStageService;
	private readonly GroupAccessService $groupAccessService;

	public function __construct()
	{
		$this->taskStageService = Container::getInstance()->get(TaskStageService::class);
		$this->groupAccessService = Container::getInstance()->get(GroupAccessService::class);
	}

	public function getByGroupId(StageParams $stageParams): StageCollection
	{
		if (
			$stageParams->checkAccess &&
			!$this->groupAccessService->canView($stageParams->userId, $stageParams->groupId)
		)
		{
			return new StageCollection();
		}

		return $this->taskStageService->getStagesByGroupId($stageParams->groupId);
	}
}
