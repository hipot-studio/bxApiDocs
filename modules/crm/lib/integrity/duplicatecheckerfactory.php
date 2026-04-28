<?php

namespace Bitrix\Crm\Integrity;

use CCrmOwnerType;

final class DuplicateCheckerFactory
{
	public function create(int $entityTypeId): ?DuplicateChecker
	{
		return match ($entityTypeId) {
			CCrmOwnerType::Lead => new LeadDuplicateChecker(),
			CCrmOwnerType::Contact => new ContactDuplicateChecker(),
			CCrmOwnerType::Company => new CompanyDuplicateChecker(),
			default => null,
		};
	}
}
