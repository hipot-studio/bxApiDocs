<?php

namespace Bitrix\Disk\Internal\Service\Access;

use Bitrix\Disk\BaseObject;
use Bitrix\Disk\Driver;
use Bitrix\Disk\ProxyType\Group;
use Bitrix\Disk\ProxyType\User;
use Bitrix\Disk\RightsManager;
use Bitrix\Disk\Storage;
use Bitrix\Main\Loader;
use Bitrix\Socialnetwork\UserToGroupTable;

final class AccessContactCandidateSource implements AccessContactCandidateSourceInterface
{
	public function getPersonalStorageOwnerId(BaseObject $object): ?int
	{
		$storage = $this->getStorage($object);
		if ($storage === null || !($storage->getProxyType() instanceof User))
		{
			return null;
		}

		return (int)$storage->getEntityId();
	}

	public function getGroupId(BaseObject $object): ?int
	{
		$storage = $this->getStorage($object);
		if ($storage === null || !($storage->getProxyType() instanceof Group))
		{
			return null;
		}

		return (int)$storage->getEntityId();
	}

	public function getAuthorId(BaseObject $object): ?int
	{
		$authorId = $object->getRealObject()?->getCreatedBy();

		return $authorId === null ? null : (int)$authorId;
	}

	public function getGroupOwnerId(int $groupId): ?int
	{
		if ($groupId <= 0 || !Loader::includeModule('socialnetwork'))
		{
			return null;
		}

		$row = UserToGroupTable::query()
			->setSelect(['USER_ID'])
			->where('GROUP_ID', $groupId)
			->where('ROLE', UserToGroupTable::ROLE_OWNER)
			->setOrder(['USER_ID' => 'ASC'])
			->setLimit(1)
			->fetch()
		;

		return $row ? (int)$row['USER_ID'] : null;
	}

	public function getGroupModeratorIds(int $groupId, int $limit): array
	{
		if ($groupId <= 0 || $limit <= 0 || !Loader::includeModule('socialnetwork'))
		{
			return [];
		}

		$rows = UserToGroupTable::query()
			->setSelect(['USER_ID'])
			->where('GROUP_ID', $groupId)
			->where('ROLE', UserToGroupTable::ROLE_MODERATOR)
			->setOrder(['USER_ID' => 'ASC'])
			->setLimit($limit)
			->fetchAll()
		;

		return array_map(static fn(array $row): int => (int)$row['USER_ID'], $rows);
	}

	public function getFullAccessRights(BaseObject $object): array
	{
		$realObject = $object->getRealObject();
		if ($realObject === null)
		{
			return [];
		}

		$rightsManager = Driver::getInstance()->getRightsManager();
		$fullTaskId = (int)$rightsManager->getTaskIdByName(RightsManager::TASK_FULL);
		$rights = [];

		// Normalized rights include the ones inherited from parent folders, with opposite pairs removed.
		foreach ($rightsManager->getAllListNormalizeRights($realObject) as $right)
		{
			if ((int)($right['TASK_ID'] ?? 0) !== $fullTaskId)
			{
				continue;
			}

			$rights[] = [
				'ACCESS_CODE' => (string)($right['ACCESS_CODE'] ?? ''),
				'NEGATIVE' => (int)($right['NEGATIVE'] ?? 0),
			];
		}

		return $rights;
	}

	private function getStorage(BaseObject $object): ?Storage
	{
		return $object->getRealObject()?->getStorage();
	}
}
