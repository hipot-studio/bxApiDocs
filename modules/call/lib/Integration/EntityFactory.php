<?php

namespace Bitrix\Call\Integration;

use Bitrix\Call\Call;
use Bitrix\Main\ArgumentException;

/**
 * @internal
 */
class EntityFactory
{
	/**
	 * Return proxy object, to access entity, associated with the call.
	 *
	 * @param Call $call The call object.
	 * @param string $entityType Type of the associated entity.
	 * @param integer $entityId Id of the associated entity.
	 * @return AbstractEntity
	 * @throws ArgumentException
	 */
	public static function createEntity(Call $call, $entityType, $entityId): AbstractEntity
	{
		if($entityType === EntityType::CHAT)
		{
			return new Chat($call, $entityId);
		}

		throw new ArgumentException("Unknown entity type: " . $entityType);
	}
}