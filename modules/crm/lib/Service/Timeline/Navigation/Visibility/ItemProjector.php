<?php

namespace Bitrix\Crm\Service\Timeline\Navigation\Visibility;

use Bitrix\Crm\Service\Timeline\Context;
use Bitrix\Crm\Service\Timeline\Item;
use Bitrix\Crm\Service\Timeline\Item\Compatible\Model as CompatibleModel;
use Bitrix\Crm\Service\Timeline\Item\LogMessage\Binding\Base as BindingItem;
use Bitrix\Crm\Service\Timeline\Item\Model;
use Bitrix\Crm\Service\Timeline\Item\NotAvailable;

final class ItemProjector
{
	public function __construct(
		private readonly Context $context,
		private readonly ?BatchAccessLoader $accessLoader = null,
	)
	{
	}

	/** @param Item[] $items */
	public function project(array $items): array
	{
		$states = ($this->accessLoader ?? new BatchAccessLoader())->load($items, $this->context);
		$result = [];
		foreach ($items as $index => $item)
		{
			$projected = $this->projectItem($item, $states[$index]);
			if ($projected !== null)
			{
				$result[] = $projected;
			}
		}

		return $result;
	}

	private function projectItem(Item $item, array $state): ?Item
	{
		$model = $item->getModel();
		if ($model instanceof CompatibleModel)
		{
			$rawData = $model->getData();
			$model = Model::createFromArray($rawData)
				->setIsFixed(($rawData['IS_FIXED'] ?? 'N') === 'Y')
			;
		}
		$associatedTypeId = (int)$model->getAssociatedEntityTypeId();

		if ($associatedTypeId === \CCrmOwnerType::Activity)
		{
			if (!$state['exists'])
			{
				return new NotAvailable($this->context, $model);
			}

			if (!$state['canRead'])
			{
				return null;
			}

			if (!$state['communicationVisible'])
			{
				return new NotAvailable($this->context, $model);
			}
		}
		elseif ($item instanceof BindingItem && !$state['relatedCanRead'])
		{
			return new NotAvailable($this->context, $model);
		}

		return $item;
	}

}
