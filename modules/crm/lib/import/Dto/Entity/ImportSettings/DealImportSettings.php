<?php

namespace Bitrix\Crm\Import\Dto\Entity\ImportSettings;

use Bitrix\Crm\Import\Dto\Entity\AbstractImportSettings;
use CCrmOwnerType;

final class DealImportSettings extends AbstractImportSettings
{
	public function getEntityTypeId(): int
	{
		return CCrmOwnerType::Deal;
	}
}
