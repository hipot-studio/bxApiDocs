<?php

declare(strict_types=1);

namespace Bitrix\Booking\Internals\Integration\Crm\DataLoader;

use Bitrix\Booking\Entity\BaseEntityCollection;
use Bitrix\Booking\Entity\ExternalData\ExternalDataItem;
use Bitrix\Booking\Internals\Integration\Crm\DealDataProvider;
use Bitrix\Booking\Internals\Integration\Crm\ExternalDataItemExtractor;
use Bitrix\Booking\Internals\Service\DataLoader\DataLoaderInterface;
use Bitrix\Crm\Item;
use Bitrix\Main\Loader;

class DealDataLoader implements DataLoaderInterface
{
	public function __construct(
		private readonly DealDataProvider $dealDataProvider,
		private readonly ExternalDataItemExtractor $externalDataExtractor,
	)
	{
	}

	public function loadForCollection(BaseEntityCollection $collection): void
	{
		if (!Loader::includeModule('crm'))
		{
			return;
		}

		$dealIds = $this->externalDataExtractor->getDealIdsFromCollections([$collection]);
		if (empty($dealIds))
		{
			return;
		}

		$deals = $this->dealDataProvider->getByIds($dealIds);
		if (empty($deals))
		{
			return;
		}

		$this->processDealData($collection, $deals);
	}

	private function processDealData(BaseEntityCollection $collection, array $deals): void
	{
		/** @var ExternalDataItem $externalDataItem */
		foreach ($collection as $externalDataItem)
		{
			$dealId = $this->externalDataExtractor->getDealId($externalDataItem);
			if ($dealId === null)
			{
				continue;
			}
			if (!isset($deals[$dealId]))
			{
				continue;
			}

			/** @var Item\Deal $deal */
			$deal = $deals[$dealId];

			$externalDataItem->setData([
				'currencyId' => $deal->getCurrencyId(),
				'opportunity' => $deal->getOpportunity(),
				'createdTimestamp' => $deal->getCreatedTime()?->getTimestamp(),
			]);
		}
	}
}
