<?php

namespace Bitrix\Sign\Service\Integration\Disk;

use Bitrix\Disk\File;

final class SecurityContext extends \Bitrix\Disk\Security\SecurityContext
{
	public function canAdd($targetId): bool
	{
		return false;
	}

	public function canRead($objectId): bool
	{
		return $this->isOwner($objectId);
	}

	public function canUpdate($objectId): bool
	{
		return $this->isOwner($objectId);
	}

	public function canChangeRights($objectId): bool
	{
		return false;
	}

	public function canChangeSettings($objectId): bool
	{
		return false;
	}

	public function canCreateWorkflow($objectId): bool
	{
		return false;
	}

	public function canDelete($objectId): bool
	{
		return false;
	}

	public function canMarkDeleted($objectId): bool
	{
		return false;
	}

	public function canMove($objectId, $targetId): bool
	{
		return false;
	}

	public function canRename($objectId): bool
	{
		return false;
	}

	public function canRestore($objectId): bool
	{
		return false;
	}

	public function canShare($objectId): bool
	{
		return false;
	}

	public function canStartBizProc($objectId): bool
	{
		return false;
	}

	public function getSqlExpressionForList($columnObjectId, $columnCreatedBy): string
	{
		return '1 = 0';
	}

	private function isOwner(int $objectId): bool
	{
		$file = File::getById($objectId);

		return $file !== null && (int)$file?->getCreatedBy() === $this->getUserId();
	}
}
