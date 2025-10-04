<?php

declare(strict_types=1);

namespace Bitrix\Booking\Entity\ExternalData;

use Bitrix\Booking\Entity\BaseEntityCollection;

/**
 * @method ExternalDataItem|null getFirstCollectionItem()
 * @method ExternalDataCollection[] getIterator()
 */
class ExternalDataCollection extends BaseEntityCollection
{
	public function __construct(ExternalDataItem ...$items)
	{
		foreach ($items as $item)
		{
			$this->collectionItems[] = $item;
		}
	}

	public static function mapFromArray(array $props): self
	{
		$externalDataItems = array_map(
			static function ($item)
			{
				return ExternalDataItem::mapFromArray($item);
			},
			$props
		);

		return new ExternalDataCollection(...$externalDataItems);
	}

	public function diff(ExternalDataCollection $collectionToCompare): ExternalDataCollection
	{
		return new ExternalDataCollection(...$this->baseDiff($collectionToCompare));
	}

	public function getByModuleAndType(string $moduleId, string|null $entityType = null): ExternalDataCollection
	{
		$collection = new self();

		/** @var ExternalDataItem $externalDataItem */
		foreach ($this as $externalDataItem)
		{
			if ($externalDataItem->getModuleId() !== $moduleId)
			{
				continue;
			}

			if ($entityType && $externalDataItem->getEntityTypeId() !== $entityType)
			{
				continue;
			}

			$collection->add($externalDataItem);
		}

		return $collection;
	}
}
