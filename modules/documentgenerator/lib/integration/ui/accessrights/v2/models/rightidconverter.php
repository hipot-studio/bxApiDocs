<?php

namespace Bitrix\DocumentGenerator\Integration\UI\AccessRights\V2\Models;

use Bitrix\UI\AccessRights\V2\Contract\AccessRightsBuilder\Provider\Structure\Entity;
use Bitrix\UI\AccessRights\V2\Contract\AccessRightsBuilder\Provider\Structure\Permission;

final class RightIdConverter implements \Bitrix\UI\AccessRights\V2\Contract\AccessRightsBuilder\Provider\Models\RightIdConverter
{
	public function __construct(
		private readonly string $separator = '~~~',
	)
	{
	}

	public function buildUIId(Entity $entity, Permission $permission): string
	{
		$entityId = $entity->getId();
		$actionId = $permission->getAction()->getId();

		return implode($this->separator, [$entityId, $actionId]);
	}

	public function parseUIId(string $uiId): ?RightId
	{
		$parts = explode($this->separator, $uiId);
		if (count($parts) < 2)
		{
			return null;
		}

		return new RightId($parts[0], $parts[1]);
	}

	/**
	 * @param RightModel $model
	 * @return RightId|null
	 */
	public function parseRightModel(\Bitrix\UI\AccessRights\V2\Contract\AccessRightsBuilder\Provider\Models\RightModel $model): ?RightId
	{
		return new RightId($model->entityId, $model->actionId);
	}
}
