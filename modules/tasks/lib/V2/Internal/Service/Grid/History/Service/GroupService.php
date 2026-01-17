<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Grid\History\Service;

use Bitrix\Tasks\Integration\SocialNetwork\Collab\Url\UrlManager;
use Bitrix\Tasks\V2\Internal\Entity\GroupCollection;
use Bitrix\Tasks\V2\Internal\Integration\Socialnetwork\Service\GroupAccessService;
use Bitrix\Tasks\V2\Internal\Repository\GroupRepositoryInterface;

class GroupService
{
	public function __construct(
		private readonly GroupRepositoryInterface $groupRepository,
		private readonly GroupAccessService $groupAccessService,
	)
	{

	}

	public function getGroups(array $groupIds): GroupCollection
	{
		if (empty($groupIds))
		{
			return new GroupCollection();
		}

		return $this->groupRepository->getByIds($groupIds);
	}

	public function fillGroup(GroupCollection $groupEntities, int $groupId, int $userId): ?array
	{
		if ($groupId <= 0 || $userId <= 0)
		{
			return null;
		}

		$groupEntity = $groupEntities->findOneById($groupId);

		if ($groupEntity === null || !$this->groupAccessService->canViewGroup($userId, $groupEntity))
		{
			return [];
		}

		return [
			'name' => $groupEntity->name ?? '',
			'link' => UrlManager::getUrlByType($groupId, $groupEntity->type),
		];
	}
}
