<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Access\UnifiedLink;

use Bitrix\Disk\File;
use Bitrix\Disk\Integration\Collab\CollabService;
use Bitrix\Disk\Internal\Service\UnifiedLink\Configuration;
use Bitrix\Disk\Internal\Repository\UnifiedLinkAccessRepository;
use Bitrix\Disk\User;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\LoaderException;
use Bitrix\Main\ObjectPropertyException;
use Bitrix\Main\SystemException;

class UnifiedLinkAccessCheckHandler extends ChainableAccessCheckHandler
{
	private CollabService $collabService;
	private User $user;

	public function __construct(CollabService $collabService)
	{
		$this->collabService = $collabService;
		$this->user = User::loadById((int)CurrentUser::get()->getId());
	}
	protected function doCheck(File $file): UnifiedLinkAccessLevel
	{
		$unifiedLinkAccessLevel = UnifiedLinkAccessRepository::getByObjectId((int)$file->getId());
		$checkAccessByLink = $unifiedLinkAccessLevel ?? Configuration::getDefaultAccessLevel();

		if ($this->user->isIntranetUser())
		{
			return $checkAccessByLink;
		}

		if ($this->user->isCollaber() && $this->isUserMemberOfCollab($file))
		{
			return $checkAccessByLink;
		}

		return UnifiedLinkAccessLevel::Denied;
	}

	/**
	 * @param File $file
	 * @return bool
	 * @throws ArgumentException
	 * @throws LoaderException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	private function isUserMemberOfCollab(File $file): bool
	{
		$collab = $this->collabService->getCollabByStorage($file->getStorage());

		return $collab && in_array((int)$this->user->getId(), $collab->getUserMemberIds(), true);
	}
}