<?php

namespace Bitrix\Crm\Import\Strategy\DuplicateControl;

use Bitrix\Crm\Import\Contract\Strategy\DuplicateControlStrategyInterface;
use Bitrix\Crm\Import\Result\DuplicateControlProcessResult;

final class DuplicateControlNoControlStrategy implements DuplicateControlStrategyInterface
{
	public function processDuplicateControl(int $entityTypeId, array $fieldNames, array $itemValues): DuplicateControlProcessResult
	{
		return new DuplicateControlProcessResult(
			isDuplicate: false,
			entityTypeId: $entityTypeId,
			duplicateIds: [],
		);
	}
}
