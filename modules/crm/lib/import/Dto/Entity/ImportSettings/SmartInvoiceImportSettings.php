<?php

namespace Bitrix\Crm\Import\Dto\Entity\ImportSettings;

use CCrmOwnerType;

final class SmartInvoiceImportSettings extends DynamicImportSettings
{
	public function __construct()
	{
		parent::__construct(CCrmOwnerType::SmartInvoice);
	}
}
