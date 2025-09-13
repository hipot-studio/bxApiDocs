<?php

declare(strict_types=1);

namespace Bitrix\Booking\Provider\Trait;

use Bitrix\Booking\Entity\BaseEntityCollection;
use Bitrix\Booking\Internals\Container;

trait ExternalDataTrait
{
	public function withExternalData(BaseEntityCollection $entityCollection): self
	{
		$externalDataCollections = [];

		foreach ($entityCollection as $entity)
		{
			$externalDataCollections[] = $entity->getExternalDataCollection();
		}

		Container::getProviderManager()::getCurrentProvider()
			?->getDataProvider()
			?->loadDataForCollection(...$externalDataCollections);

		return $this;
	}
}
