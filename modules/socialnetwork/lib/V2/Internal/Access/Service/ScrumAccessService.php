<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Access\Service;

use Bitrix\Socialnetwork\Internals\Registry\GroupRegistry;
use Bitrix\Socialnetwork\Item\Workgroup\Type;
use Bitrix\Socialnetwork\Permission\GroupAccessController;
use Bitrix\Socialnetwork\Permission\GroupDictionary;

class ScrumAccessService implements GridAccessServiceInterface
{
	public function canRead(int $userId, int $entityId): bool
	{
		return $this->can($userId, GroupDictionary::VIEW, $entityId);
	}

	public function canUpdate(int $userId, int $entityId): bool
	{
		return $this->can($userId, GroupDictionary::UPDATE, $entityId);
	}

	public function canDelete(int $userId, int $entityId): bool
	{
		return $this->can($userId, GroupDictionary::DELETE, $entityId);
	}

	public function canLeave(int $userId, int $entityId): bool
	{
		return $this->can($userId, GroupDictionary::LEAVE, $entityId);
	}

	public function canJoin(int $userId, int $entityId): bool
	{
		return $this->can($userId, GroupDictionary::JOIN, $entityId);
	}

	private function can(int $userId, string $action, int $entityId): bool
	{
		if ($userId <= 0 || $entityId <= 0)
		{
			return false;
		}

		$group = GroupRegistry::getInstance()->get($entityId);
		if ($group?->getType() !== Type::Scrum)
		{
			return false;
		}

		return GroupAccessController::can($userId, $action, $entityId);
	}
}
