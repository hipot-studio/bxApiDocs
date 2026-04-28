<?php

namespace Bitrix\Crm\Import\Dto\Entity\ImportSettings\Trait;

use Bitrix\Crm\Import\Dto\Entity\RequisiteOptions;

trait HasRequisiteOptions
{
	protected RequisiteOptions $requisiteOptions;

	public function getRequisiteOptions(): RequisiteOptions
	{
		return $this->requisiteOptions;
	}
}
