<?php

declare(strict_types=1);

namespace Bitrix\Crm\MessageSender;

use Bitrix\Crm\Integration\SmsManager;
use Bitrix\Crm\Service\Container;

/**
 * Single source of truth for "does the message sender / SMS editor exist for this entity type".
 * Shared by the timeline editor and the custom-template zone provider so their surfaces cannot drift apart.
 */
final class Availability
{
	public static function isAvailableForEntityType(int $entityTypeId): bool
	{
		if (!SmsManager::canUse())
		{
			return false;
		}

		if (in_array($entityTypeId, [\CCrmOwnerType::ShipmentDocument, \CCrmOwnerType::StoreDocument], true))
		{
			return false;
		}

		if (\CCrmOwnerType::isUseDynamicTypeBasedApproach($entityTypeId))
		{
			$factory = Container::getInstance()->getFactory($entityTypeId);

			return $factory !== null && $factory->isClientEnabled();
		}

		return true;
	}
}
