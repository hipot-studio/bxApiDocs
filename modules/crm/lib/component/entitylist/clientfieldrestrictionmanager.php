<?php

namespace Bitrix\Crm\Component\EntityList;

use Bitrix\Crm\Restriction\ClientFieldsRestriction;
use Bitrix\Crm\Restriction\RestrictionManager;

class ClientFieldRestrictionManager extends FieldRestrictionManagerBase
{
	final public function hasRestrictions(): bool
	{
		return $this->getClientFieldsRestriction()->isExceeded();
	}

	final public function getJsCallback(): string
	{
		return $this->getClientFieldsRestriction()->prepareInfoHelperScript();
	}

	final protected function isFieldRestricted(string $fieldName): bool
	{
		return (
			(
				str_starts_with($fieldName, 'CONTACT_')
				|| str_starts_with($fieldName, 'COMPANY_')
				|| str_starts_with($fieldName, 'CONTACT.')
				|| str_starts_with($fieldName, 'COMPANY.')
			)
			&& !in_array($fieldName, ['CONTACT_ID', 'COMPANY_ID'])
		);
	}

	private function getClientFieldsRestriction(): ClientFieldsRestriction
	{
		$entityTypeId = $this->entityTypeId ?? \CCrmOwnerType::Deal;

		return RestrictionManager::getClientFieldsRestriction($entityTypeId);
	}
}
