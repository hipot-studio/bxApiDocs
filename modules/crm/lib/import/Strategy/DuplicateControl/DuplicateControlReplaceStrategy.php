<?php

namespace Bitrix\Crm\Import\Strategy\DuplicateControl;

use Bitrix\Crm\Field;
use Bitrix\Crm\Item;
use Bitrix\Crm\Multifield\Assembler;
use Bitrix\Crm\Multifield\Collection;

final class DuplicateControlReplaceStrategy extends AbstractDuplicateControlStrategy
{
	protected function processDuplicateItem(Item $duplicateItem, Field $field, mixed $importValue): void
	{
		if ($field->getName() === Item::FIELD_NAME_FM)
		{
			$newFmCollection = new Collection();
			Assembler::updateCollectionByArray($newFmCollection, $importValue);

			if ($newFmCollection->isEmpty())
			{
				return;
			}

			$duplicateItem->set(Item::FIELD_NAME_FM, $newFmCollection);

			return;
		}

		$isImportValueEmpty = $field->isValueEmpty($importValue);
		if (!$isImportValueEmpty)
		{
			$duplicateItem->setFromCompatibleData([
				$field->getName() => $importValue,
			]);
		}
	}
}
