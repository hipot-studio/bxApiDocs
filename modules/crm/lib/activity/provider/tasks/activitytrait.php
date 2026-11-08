<?php

namespace Bitrix\Crm\Activity\Provider\Tasks;

use Bitrix\Crm\ItemIdentifier;

trait ActivityTrait
{
	public static function invalidate(string $key): void
	{
		unset(static::$cache[$key]);
	}

	public static function invalidateAll(): void
	{
		static::$cache = [];
	}

	public function getCacheKey(int $entityId, ?ItemIdentifier $identifier = null): string
	{
		$key = static::getSubject() . '_' . $entityId;
		if ($identifier !== null)
		{
			$key .= '_' . $identifier->getEntityTypeId() . '_' . $identifier->getEntityId();
		}

		return $key;
	}
}
