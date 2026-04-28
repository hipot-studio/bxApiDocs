<?php

declare(strict_types=1);

namespace Bitrix\Booking\Internals\Integration\Crm;

use Bitrix\Booking\Entity\ExternalData\ExternalDataItem;

class ExternalDataItemExtractor
{
	private const MODULE_ID = 'crm';

	public function getDealId(ExternalDataItem $externalDataItem): ?int
	{
		if (!$this->isDeal($externalDataItem))
		{
			return null;
		}

		return (int)$externalDataItem->getValue();
	}

	private function isDeal(ExternalDataItem $externalDataItem): bool
	{
		return $externalDataItem->getModuleId() === self::MODULE_ID
			&& $externalDataItem->getEntityTypeId() === 'DEAL'
		;
	}

	public function getDealIdsFromCollections(array $externalDataCollections): array
	{
		$result = [];

		foreach ($externalDataCollections as $externalDataCollection)
		{
			/** @var ExternalDataItem $externalDataItem */
			foreach ($externalDataCollection as $externalDataItem)
			{
				$dealId = $this->getDealId($externalDataItem);
				if ($dealId === null)
				{
					continue;
				}

				$result[$dealId] = true;
			}
		}

		return array_keys($result);
	}
}
