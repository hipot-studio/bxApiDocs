<?php

namespace Bitrix\Socialnetwork\Permission\Rule\Trait;

use Bitrix\Socialnetwork\Permission\Model\GroupModel;
use Bitrix\Socialnetwork\Permission\Rule\GroupRelationCache;
use Bitrix\Socialnetwork\Internals\Group\GroupEntity;
use Bitrix\Socialnetwork\Item\Workgroup\AccessManager;
use Bitrix\Socialnetwork\Space\Member;

trait AccessTrait
{
	protected function getAccessManager(GroupModel $item, ?int $targetUserId, ?int $currentUserId): AccessManager
	{
		$group = $this->getEntityByModel($item);

		$target = $this->getRelation($item->getId(), $targetUserId);
		$current = $this->getRelation($item->getId(), $currentUserId);

		return new AccessManager(
			$group,
			$target,
			$current,
			[],
			[
				'userId' => $currentUserId,
			],
		);
	}

	protected function getEntityByModel(GroupModel $item): GroupEntity
	{
		return GroupEntity::wakeUpObject($item->getDomainObject());
	}

	protected function getRelation(int $groupId, ?int $userId): ?Member
	{
		if ($userId === null)
		{
			return null;
		}

		return GroupRelationCache::get($groupId, $userId);
	}
}
