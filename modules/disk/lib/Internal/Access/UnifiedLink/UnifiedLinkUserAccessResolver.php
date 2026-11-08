<?php
declare(strict_types=1);

namespace Bitrix\Disk\Internal\Access\UnifiedLink;

use Bitrix\Disk\BaseObject;
use Bitrix\Disk\Integration\Collab\CollabService;
use Bitrix\Disk\Storage;
use Bitrix\Disk\User;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\LoaderException;
use Bitrix\Main\NotImplementedException;
use Bitrix\Main\ObjectPropertyException;
use Bitrix\Main\SystemException;
use Bitrix\Socialnetwork\Collab\Collab;

final class UnifiedLinkUserAccessResolver
{
	protected CollabService $collabService;
	protected array $collabMembershipCache = [];

	/**
	 * @param int $userId
	 */
	public function __construct(
		private readonly int $userId,
	)
	{
		$this->collabService = new CollabService();
	}

	/**
	 * @param BaseObject $baseObject
	 * @return bool
	 * @throws ArgumentException
	 * @throws LoaderException
	 * @throws NotImplementedException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	public function canUseUnifiedLink(BaseObject $baseObject): bool
	{
		$user = User::loadById($this->userId);

		if (!$user instanceof User)
		{
			return false;
		}

		if ($user->isIntranetUser())
		{
			return true;
		}

		if (!$user->isCollaber())
		{
			return false;
		}

		return $this->isUserMemberOfCollabByObject($baseObject, (int)$user->getId());
	}

	/**
	 * @param BaseObject $baseObject
	 * @param int $userId
	 * @return bool
	 * @throws NotImplementedException
	 * @throws ArgumentException
	 * @throws LoaderException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	protected function isUserMemberOfCollabByObject(BaseObject $baseObject, int $userId): bool
	{
		$storageId = (int)$baseObject->getStorageId();
		$cacheKey = "$storageId:$userId";

		if (!array_key_exists($cacheKey, $this->collabMembershipCache))
		{
			$this->collabMembershipCache[$cacheKey] = false;
			$storage = Storage::loadById($storageId);

			if ($storage instanceof Storage)
			{
				$collab = $this->collabService->getCollabByStorage($storage);

				$this->collabMembershipCache[$cacheKey] =
					$collab instanceof Collab
					&& in_array($userId, $collab->getUserMemberIds(), true)
				;
			}
		}

		return $this->collabMembershipCache[$cacheKey];
	}
}
