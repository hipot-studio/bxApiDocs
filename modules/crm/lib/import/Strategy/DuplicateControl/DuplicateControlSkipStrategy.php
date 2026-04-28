<?php

namespace Bitrix\Crm\Import\Strategy\DuplicateControl;

use Bitrix\Crm\Field;
use Bitrix\Crm\Item;

final class DuplicateControlSkipStrategy extends AbstractDuplicateControlStrategy
{
	protected function processDuplicateItem(Item $duplicateItem, Field $field, mixed $importValue): void
	{
		// Next we will write the duplicates to a separate file
	}

	protected function isChangeDuplicateItems(): bool
	{
		return false;
	}
}
