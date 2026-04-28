<?php

namespace Bitrix\Crm\Import\Strategy\DuplicateControl;

use Bitrix\Crm\Field;
use Bitrix\Crm\Item;
use Bitrix\Crm\Multifield\Assembler;
use Bitrix\Crm\ProductRowCollection;

final class DuplicateControlMergeStrategy extends AbstractDuplicateControlStrategy
{
	protected function processDuplicateItem(Item $duplicateItem, Field $field, mixed $importValue): void
	{
		if ($field->isMultiple())
		{
			if (!is_array($importValue))
			{
				return;
			}

			if ($field->getName() === Item::FIELD_NAME_FM)
			{
				$fm = $duplicateItem->getFm();
				Assembler::updateCollectionByArray($fm, $importValue);

				$duplicateItem->setFm($fm);

				return;
			}

			$value = $duplicateItem->get($field->getName());

			if ($value instanceof ProductRowCollection)
			{
				$value = $value->toArray();
			}

			if ($field->isValueEmpty($value))
			{
				$value = [];
			}

			if (!is_array($value))
			{
				return;
			}

			$newValue = array_merge($value, $importValue);

			$duplicateItem->setFromCompatibleData([
				$field->getName() => $newValue,
			]);

			return;
		}

		$isImportValueEmpty = $field->isValueEmpty($importValue);
		$isDuplicateItemValueEmpty = $field->isItemValueEmpty($duplicateItem);

		if (!$isImportValueEmpty && $isDuplicateItemValueEmpty)
		{
			$duplicateItem->setFromCompatibleData([
				$field->getName() => $importValue,
			]);
		}
	}
}
