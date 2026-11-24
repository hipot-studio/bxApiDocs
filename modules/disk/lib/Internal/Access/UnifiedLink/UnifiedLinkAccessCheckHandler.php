<?php
declare(strict_types=1);

namespace Bitrix\Disk\Internal\Access\UnifiedLink;

use Bitrix\Disk\BaseObject;
use Bitrix\Disk\Internal\Service\UnifiedLink\Configuration;
use Bitrix\Disk\Internal\Repository\UnifiedLinkAccessRepository;

class UnifiedLinkAccessCheckHandler extends ChainableAccessCheckHandler
{
	protected UnifiedLinkUserAccessResolver $userAccessResolver;

	public function __construct(
		int $userId,
		private readonly bool $fresh = false,
		private readonly array $snapshot = [],
	)
	{
		$this->userAccessResolver = new UnifiedLinkUserAccessResolver($userId);
	}

	protected function doCheck(BaseObject $baseObject): UnifiedLinkAccessLevel
	{
		return $this->userAccessResolver->canUseUnifiedLink($baseObject)
			? $this->getAccessLevelForObject($baseObject)
			: UnifiedLinkAccessLevel::Denied
		;
	}

	protected function doCheckCollection(array $baseObjects): array
	{
		$objectIds = array_keys($baseObjects);
		$result = [];
		$accessLevels = UnifiedLinkAccessRepository::getByObjectIds($objectIds);

		foreach ($baseObjects as $objectId => $baseObject)
		{
			if (!$this->userAccessResolver->canUseUnifiedLink($baseObject))
			{
				continue;
			}

			$result[$objectId] = $accessLevels[$objectId] ?? $this->getDefaultAccessLevel($baseObject);
		}

		return $result;
	}

	protected function getAccessSource(): UnifiedLinkAccessSource
	{
		return UnifiedLinkAccessSource::UnifiedLink;
	}

	protected function getAccessLevelForObject(BaseObject $baseObject): UnifiedLinkAccessLevel
	{
		$id = (int)$baseObject->getId();
		$level = $this->fresh
			? ($this->snapshot['levels'][$id] ?? null)
			: UnifiedLinkAccessRepository::getByObjectId($id)
		;

		return $level
			?? $this->getDefaultAccessLevel($baseObject)
		;
	}

	protected function getDefaultAccessLevel(BaseObject $baseObject): UnifiedLinkAccessLevel
	{
		return Configuration::getDefaultAccessLevelForObject($baseObject);
	}
}
