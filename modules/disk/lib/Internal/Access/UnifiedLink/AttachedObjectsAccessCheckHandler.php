<?php
declare(strict_types=1);

namespace Bitrix\Disk\Internal\Access\UnifiedLink;

use Bitrix\Disk\AttachedObject;
use Bitrix\Disk\BaseObject;
use Bitrix\Disk\Internal\Service\UnifiedLink\BaseObjectResolver;

class AttachedObjectsAccessCheckHandler extends ChainableAccessCheckHandler
{
	public function __construct(
		private readonly int $userId,
		private readonly ?AttachedObject $attachedObject,
	)
	{
	}

	protected function doCheck(BaseObject $baseObject): UnifiedLinkAccessLevel
	{
		$attachedObject = BaseObjectResolver::resolveAttachedObject($baseObject, $this->attachedObject);
		if ($attachedObject === null)
		{
			return UnifiedLinkAccessLevel::Denied;
		}

		return $this->getAccessLevel($attachedObject);
	}

	protected function doCheckCollection(array $baseObjects): array
	{
		if (!$this->attachedObject instanceof AttachedObject)
		{
			return [];
		}

		$objectId = (int)$this->attachedObject->getObjectId();
		$baseObject = $baseObjects[$objectId] ?? null;
		if (!$baseObject instanceof BaseObject)
		{
			return [];
		}

		$attachedObject = BaseObjectResolver::resolveAttachedObject($baseObject, $this->attachedObject);
		if ($attachedObject === null)
		{
			return [];
		}

		return [
			$objectId => $this->getAccessLevel($attachedObject),
		];
	}

	protected function getAccessSource(): UnifiedLinkAccessSource
	{
		return UnifiedLinkAccessSource::AttachedObject;
	}

	protected function getAccessLevel(AttachedObject $attachedObject): UnifiedLinkAccessLevel
	{
		if ($attachedObject->canUpdate($this->userId))
		{
			return UnifiedLinkAccessLevel::Edit;
		}

		if ($attachedObject->canRead($this->userId))
		{
			return UnifiedLinkAccessLevel::Read;
		}

		return UnifiedLinkAccessLevel::Denied;
	}
}
