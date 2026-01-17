<?php

namespace Bitrix\Crm\Recycling;

use Bitrix\Crm\Traits\Singleton;
use Bitrix\Main\Config\Option;
use CCrmOwnerType;

class UserFieldsRecycleBinStorageChecker
{
	use Singleton;

	public function isReady(int $entityTypeId): bool
	{
		$entityName = CCrmOwnerType::ResolveName($entityTypeId);

		return Option::get('crm', 'CRM_RECYCLE_BIN_USER_FIELDS_STORAGE_UNLOCKED_' . $entityName, 'Y') === 'Y';
	}
}
