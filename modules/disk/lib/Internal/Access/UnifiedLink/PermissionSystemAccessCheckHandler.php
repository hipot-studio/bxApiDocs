<?php
declare(strict_types=1);

namespace Bitrix\Disk\Internal\Access\UnifiedLink;

use Bitrix\Disk\BaseObject;
use Bitrix\Disk\RightsManager;

class PermissionSystemAccessCheckHandler extends ChainableAccessCheckHandler
{
	public function __construct(
		private readonly int $userId,
		private readonly ?array $operationsByObjectId = null,
	)
	{
	}

	protected function doCheck(BaseObject $baseObject): UnifiedLinkAccessLevel
	{
		$securityContext = $baseObject->getStorage()?->getSecurityContext($this->userId);

		if (!$securityContext)
		{
			return UnifiedLinkAccessLevel::Denied;
		}

		if ($baseObject->canUpdate($securityContext))
		{
			return UnifiedLinkAccessLevel::Edit;
		}

		if ($baseObject->canRead($securityContext))
		{
			return UnifiedLinkAccessLevel::Read;
		}

		return UnifiedLinkAccessLevel::Denied;
	}

	protected function doCheckCollection(array $baseObjects): array
	{
		$result = [];

		foreach ($baseObjects as $objectId => $baseObject)
		{
			if (is_array($this->operationsByObjectId))
			{
				$operations = $this->operationsByObjectId[(int)$objectId] ?? [];

				if (isset($operations[RightsManager::OP_EDIT]))
				{
					$result[(int)$objectId] = UnifiedLinkAccessLevel::Edit;
				}
				elseif (isset($operations[RightsManager::OP_READ]))
				{
					$result[(int)$objectId] = UnifiedLinkAccessLevel::Read;
				}

				continue;
			}

			$result[(int)$objectId] = $this->doCheck($baseObject);
		}

		return $result;
	}

	protected function getAccessSource(): UnifiedLinkAccessSource
	{
		return UnifiedLinkAccessSource::PermissionSystem;
	}
}
