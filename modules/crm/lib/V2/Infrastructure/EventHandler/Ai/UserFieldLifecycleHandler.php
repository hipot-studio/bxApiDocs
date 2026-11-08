<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\EventHandler\Ai;

use Bitrix\Crm\V2\Internal\Service\Ai\FieldFillPolicyService;
use Bitrix\Crm\V2\Public\EntityType;

/**
 * Drops the CRM state that outlives a user field of the main module once that field is gone.
 *
 * @internal
 */
final class UserFieldLifecycleHandler
{
	/**
	 * The `b_user_field` row is already deleted when the event fires, so the entity the field belonged
	 * to is resolved from the event payload instead of being read back from the storage.
	 *
	 * Repeating the call is harmless: nothing here fails on state that is already clean.
	 */
	public static function onAfterUserTypeDelete(array $fields, $id): void
	{
		$userFieldId = (int)$id;
		if ($userFieldId <= 0)
		{
			return;
		}

		$entityTypeId = (int)\CCrmOwnerType::ResolveIDByUFEntityID($fields['ENTITY_ID'] ?? '');
		if (!EntityType::isValid($entityTypeId))
		{
			return;
		}

		FieldFillPolicyService::getInstance()->clearByUserFieldId($userFieldId, $entityTypeId);
	}
}
