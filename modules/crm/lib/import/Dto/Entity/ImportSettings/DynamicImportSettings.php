<?php

namespace Bitrix\Crm\Import\Dto\Entity\ImportSettings;

use Bitrix\Crm\Import\Dto\Entity\AbstractImportSettings;
use Bitrix\Crm\Item;

class DynamicImportSettings extends AbstractImportSettings
{
	public function __construct(
		protected readonly int $entityTypeId,
	)
	{
		parent::__construct();
	}

	public function getEntityTypeId(): int
	{
		return $this->entityTypeId;
	}

	public function applyDefaultValues(array $values): array
	{
		$values = parent::applyDefaultValues($values);

		// Manual opportunity only when there is an explicit non-zero amount and no products to recalculate from.
		$hasProducts = !empty($values[Item::FIELD_NAME_PRODUCTS]);
		$opportunity = (float)($values[Item::FIELD_NAME_OPPORTUNITY] ?? 0);
		if (!$hasProducts && abs($opportunity) >= 0.01)
		{
			$values[Item::FIELD_NAME_IS_MANUAL_OPPORTUNITY] = true;
		}

		return $values;
	}
}
