<?php

namespace Bitrix\Crm\Import\Dto\Entity\ImportSettings\Trait;

use Bitrix\Crm\Import\Dto\Entity\DuplicateControl;

trait HasDuplicateControl
{
	private DuplicateControl $duplicateControl;

	public function getDuplicateControl(): DuplicateControl
	{
		return $this->duplicateControl;
	}
}
