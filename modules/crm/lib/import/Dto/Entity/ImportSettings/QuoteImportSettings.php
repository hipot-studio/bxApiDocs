<?php

namespace Bitrix\Crm\Import\Dto\Entity\ImportSettings;

use Bitrix\Crm\Import\Dto\Entity\AbstractImportSettings;
use CCrmOwnerType;

final class QuoteImportSettings extends AbstractImportSettings
{
	public function getEntityTypeId(): int
	{
		return CCrmOwnerType::Quote;
	}
}
