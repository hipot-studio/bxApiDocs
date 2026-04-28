<?php

namespace Bitrix\Crm\Import\Contract\ImportSettings;

use Bitrix\Crm\Import\Dto\Entity\DuplicateControl;

interface HasDuplicateControlInterface
{
	public function getDuplicateControl(): DuplicateControl;
}
