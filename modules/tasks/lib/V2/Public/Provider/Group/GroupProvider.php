<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Public\Provider\Group;

use Bitrix\Tasks\V2\Internal\DI\Container;
use Bitrix\Tasks\V2\Internal\Entity\Group;
use Bitrix\Tasks\V2\Internal\Integration\Socialnetwork\Service\GroupAccessService;
use Bitrix\Tasks\V2\Internal\Repository\GroupRepositoryInterface;
use Bitrix\Tasks\V2\Public\Provider\Params\Group\GroupParams;

class GroupProvider
{
	private readonly GroupAccessService $groupAccessService;
	private readonly GroupRepositoryInterface $groupRepository;

	public function __construct()
	{
		$this->groupAccessService = Container::getInstance()->get(GroupAccessService::class);
		$this->groupRepository = Container::getInstance()->get(GroupRepositoryInterface::class);
	}

	public function get(GroupParams $groupParams): ?Group
	{
		if (
			$groupParams->checkAccess &&
			!$this->groupAccessService->canView($groupParams->userId, $groupParams->groupId)
		)
		{
			return null;
		}

		return $this->groupRepository->getById($groupParams->groupId);
	}
}
