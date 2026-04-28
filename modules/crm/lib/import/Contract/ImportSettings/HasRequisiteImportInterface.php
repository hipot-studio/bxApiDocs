<?php

namespace Bitrix\Crm\Import\Contract\ImportSettings;

use Bitrix\Crm\Import\Dto\Entity\RequisiteOptions;

interface HasRequisiteImportInterface
{
	public function getRequisiteOptions(): RequisiteOptions;
}
