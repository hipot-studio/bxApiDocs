<?php

namespace Bitrix\CrmMobile\ProductGrid;

use Bitrix\Crm\ProductRow;
use Bitrix\Crm\Item;

final class ProductItem
{
	public static function rebuild(ProductRow $source, array $mutations, Item $entity): ProductRow
	{
		$sourceFields = $source->toArray();
		$result = ProductRow::createFromArray(array_merge($sourceFields, $mutations));

		if ($result->isNew())
		{
			$entityResult = $entity->addToProductRows($result);
		}
		else
		{
			$rowId = $result->getId();

			$fields = $result->toArray();
			unset($fields['ID']);

			$entityResult = $entity->updateProductRow($rowId, $fields);

			if ($entityResult->isSuccess())
			{
				$result = $entity->getProductRows()->getByPrimary($rowId) ?? $result;
			}
		}

		if (!$entityResult->isSuccess())
		{
			throw new \RuntimeException(implode(PHP_EOL, $entityResult->getErrorMessages()));
		}

		return $result;
	}
}
