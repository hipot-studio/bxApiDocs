<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Access\UnifiedLink;

use Bitrix\Disk\File;
use Bitrix\Disk\Integration\Collab\CollabService;
use Bitrix\Disk\Internal\Service\UnifiedLink\Configuration;
use Bitrix\Disk\Internal\Repository\UnifiedLinkAccessRepository;
use Bitrix\Disk\User;

class UnifiedLinkAccessCheckHandler extends ChainableAccessCheckHandler
{
	private CollabService $collabService;

	public function __construct(
		private readonly int $userId,
	)
	{
		$this->collabService = new CollabService();
	}
	
	protected function doCheck(File $file): UnifiedLinkAccessLevel
	{
		$unifiedLinkAccessLevel = UnifiedLinkAccessRepository::getByObjectId((int)$file->getId());
		$checkAccessByLink = $unifiedLinkAccessLevel ?? Configuration::getDefaultAccessLevel();

		$user = User::loadById($this->userId);

		if ($user->isIntranetUser())
		{
			return $checkAccessByLink;
		}

		if (
			$user->isCollaber()
			&& $this->collabService->isUserMemberOfCollabByObject(
				baseObject: $file,
				userId: (int)$user->getId(),
			)
		)
		{
			return $checkAccessByLink;
		}

		return UnifiedLinkAccessLevel::Denied;
	}
}