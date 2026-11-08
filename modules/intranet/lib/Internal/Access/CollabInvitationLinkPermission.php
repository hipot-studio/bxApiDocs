<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Access;

use Bitrix\Main\Loader;
use Bitrix\Socialnetwork\Collab\Collab;
use Bitrix\Socialnetwork\Internals\Registry\GroupRegistry;

class CollabInvitationLinkPermission
{
	public function __construct(
		private readonly ?GroupRegistry $groupRegistry = null,
	)
	{
	}

	public function canManage(int $userId, int $collabId): bool
	{
		if ($userId <= 0 || $collabId <= 0)
		{
			return false;
		}

		try
		{
			if (!$this->isSocialnetworkAvailable())
			{
				return false;
			}

			$collab = ($this->groupRegistry ?? GroupRegistry::getInstance())->get($collabId);

			return
				$collab instanceof Collab
				&& $collab->getOwnerId() === $userId
			;
		}
		catch (\Throwable)
		{
			return false;
		}
	}

	protected function isSocialnetworkAvailable(): bool
	{
		return Loader::includeModule('socialnetwork');
	}
}
