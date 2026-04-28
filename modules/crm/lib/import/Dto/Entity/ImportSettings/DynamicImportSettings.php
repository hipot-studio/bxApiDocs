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

		$values[Item::FIELD_NAME_IS_MANUAL_OPPORTUNITY] = true;

		return $values;
	}
}
