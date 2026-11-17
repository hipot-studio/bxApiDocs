<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\DockedUpload;

use Bitrix\Disk\ProxyType;
use Bitrix\Disk\Storage;
use Bitrix\Disk\SystemUser;
use Bitrix\Disk\Ui\Avatar;
use Bitrix\Disk\User;
use Bitrix\Main\Loader;
use Bitrix\Socialnetwork\Provider\GroupProvider;

/**
 * Warms the owners of a batch of destination storages, so that naming them in the section
 * header of the docked uploader costs one query per owner table instead of one per storage:
 * owners of personal storages, rows and types of groups and the files of their pictures.
 *
 * Nothing is disclosed here: the service only fills the caches the one-by-one path reads,
 * while the access gate stays with {@see QueueTargetNameResolver}.
 */
final class QueueTargetOwnerWarmer
{
	/**
	 * @param Storage[] $storages Storages of the batch, the very objects the runtime cache keeps:
	 *   group rows are written into their proxies, so another object would not see them.
	 * @param int $userId User the names are resolved for; their own storage is not warmed.
	 */
	public function warm(array $storages, int $userId): void
	{
		/** @var array<int, int> $ownerIds */
		$ownerIds = [];
		/** @var ProxyType\Group[] $groupProxies */
		$groupProxies = [];
		/** @var array<int, int> $groupIds */
		$groupIds = [];

		foreach ($storages as $storage)
		{
			if (!$storage instanceof Storage)
			{
				continue;
			}

			$proxyType = $storage->getProxyType();
			$entityId = (int)$storage->getEntityId();
			if ($entityId <= 0)
			{
				continue;
			}

			if ($proxyType instanceof ProxyType\User)
			{
				if ($entityId !== $userId && !SystemUser::isSystemUserId($entityId))
				{
					$ownerIds[$entityId] = $entityId;
				}
			}
			elseif ($proxyType instanceof ProxyType\Group)
			{
				$groupProxies[] = $proxyType;
				$groupIds[$entityId] = $entityId;
			}
		}

		$photoIds = $this->warmUsers($ownerIds);
		$imageIds = $this->warmGroups($groupProxies, $groupIds);

		Avatar::preload(array_merge($photoIds, $imageIds));
	}

	/**
	 * @param array<int, int> $ownerIds
	 * @return int[] Ids of the personal photos of the loaded owners.
	 */
	private function warmUsers(array $ownerIds): array
	{
		if ($ownerIds === [])
		{
			return [];
		}

		$photoIds = [];
		foreach (User::loadBatchByIdAsSingle(array_values($ownerIds)) as $user)
		{
			$photoId = (int)$user->getPersonalPhoto();
			if ($photoId > 0)
			{
				$photoIds[] = $photoId;
			}
		}

		return $photoIds;
	}

	/**
	 * @param ProxyType\Group[] $groupProxies
	 * @param array<int, int> $groupIds
	 * @return int[] Ids of the images of the groups.
	 */
	private function warmGroups(array $groupProxies, array $groupIds): array
	{
		if ($groupProxies === [])
		{
			return [];
		}

		$imageIds = ProxyType\Group::preloadGroups($groupProxies);

		if (Loader::includeModule('socialnetwork'))
		{
			GroupProvider::getInstance()->loadGroupTypes(...array_values($groupIds));
		}

		return array_values($imageIds);
	}
}
