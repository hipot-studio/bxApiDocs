<?php

declare(strict_types=1);

namespace Bitrix\Booking\Internals\Integration\Catalog;

use Bitrix\Booking\Entity\BaseEntityCollection;
use Bitrix\Booking\Entity\Sku\SkuCollection;
use Bitrix\Booking\Internals\Service\DataLoader\DataLoaderInterface;
use Bitrix\Catalog\Access\AccessController;
use Bitrix\Catalog\Access\ActionDictionary;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Loader;
use Bitrix\Booking;

class SkuDataLoader implements DataLoaderInterface
{
	public function __construct(
		private readonly ServiceSkuProvider $serviceSkuProvider,
		private CurrentUser|null $currentUser = null,
	)
	{
		$this->currentUser = $currentUser ?? CurrentUser::get();
	}

	/**
	 * @param SkuCollection ...$collections
	 */
	public function loadForCollection(BaseEntityCollection ...$collections): void
	{
		if (!Loader::includeModule('catalog'))
		{
			return;
		}

		if (!$this->checkCatalogReadAccess())
		{
			$this->applyForbiddenPermissions($collections);

			return;
		}

		$this->loadData($collections);
	}

	private function checkCatalogReadAccess(): bool
	{
		$userId = (int)$this->currentUser->getId();
		if (!$userId)
		{
			// current user not defined, probably it's a system action
			return true;
		}

		return AccessController::getInstance($userId)
			->check(ActionDictionary::ACTION_CATALOG_READ)
		;
	}

	/**
	 * @param SkuCollection[] $collections
	 */
	private function applyForbiddenPermissions(array $collections): void
	{
		$forbidReadPermissions = [Booking\Entity\Sku\Sku::PERMISSION_READ => false];

		foreach ($collections as $collection)
		{
			array_map(
				static fn (Booking\Entity\Sku\Sku $sku) => $sku->setPermissions($forbidReadPermissions),
				$collection->getCollectionItems(),
			);
		}
	}

	/**
	 * @param SkuCollection[] $collections
	 */
	private function loadData(array $collections): void
	{
		$entityIds = $this->extractEntityIds($collections);
		if (empty($entityIds))
		{
			return;
		}

		$services = $this->serviceSkuProvider->get($entityIds);

		if (!$services)
		{
			return;
		}

		$indexedServices = $this->indexServicesByIds($services);
		$this->fillSkuData($indexedServices, $collections);
	}

	/**
	 * @param SkuCollection[] $collections
	 */
	private function extractEntityIds(array $collections): array
	{
		$entityIds = [];
		foreach ($collections as $collection)
		{
			foreach ($collection->getCollectionItems() as $sku)
			{
				$entityIds[] = $sku->getId();
			}
		}

		return array_values(array_unique($entityIds));
	}

	/**
	 * @param array<int, Sku> $indexedServices
	 * @param SkuCollection[] $collections
	 */
	private function fillSkuData(array $indexedServices, array $collections): void
	{
		foreach ($collections as $collection)
		{
			foreach ($collection as $sku)
			{
				$skuId = $sku->getId();
				if (!isset($indexedServices[$skuId]))
				{
					continue;
				}

				$catalogSku = $indexedServices[$skuId];

				$sku->setName($catalogSku->getName());
				$sku->setPrice($catalogSku->getPrice());
				$sku->setCurrencyId($catalogSku->getCurrencyId());

				$sku->setPermissions([
					Booking\Entity\Sku\Sku::PERMISSION_READ => true,
				]);
			}
		}
	}

	/**
	 * @param Sku[] $services
	 * @return array<int, Sku>
	 */
	private function indexServicesByIds(array $services): array
	{
		$indexed = [];
		foreach ($services as $service)
		{
			$indexed[$service->getId()] = $service;
		}

		return $indexed;
	}
}
