<?php

namespace Bitrix\Crm\Import\Contract\Strategy;

use Bitrix\Crm\Import\Result\DuplicateControlProcessResult;

interface DuplicateControlStrategyInterface
{
	public function processDuplicateControl(int $entityTypeId, array $fieldNames, array $itemValues): DuplicateControlProcessResult;
}
