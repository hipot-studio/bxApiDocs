<?php

declare(strict_types=1);

namespace Bitrix\Booking\Internals\Integration\Crm;

use Bitrix\Booking\Entity\Booking\Booking;
use Bitrix\Booking\Internals\Integration\Catalog\ServiceSkuProvider;
use Bitrix\Crm\Service\Container;
use Bitrix\Main\Loader;
use CCrmOwnerType;
use Bitrix\Crm\ProductType;
use Bitrix\Crm\Service\Factory;

class DealService
{
	private ServiceSkuProvider $serviceSkuProvider;

	public function __construct(ServiceSkuProvider $serviceSkuProvider)
	{
		$this->serviceSkuProvider = $serviceSkuProvider;
	}

	public function addProductsToDeal(int $dealId, array $skuIds): array
	{
		if (!$this->isAvailable())
		{
			return [];
		}

		$skus = $this->serviceSkuProvider->get($skuIds);
		$skuProductMap = [];
		foreach ($skus as $sku)
		{
			// set price to 0 if null to avoid validation errors CCrmProductRow::Add
			$price = $sku->getPrice() ?? 0.0;

			$fields = [
				'OWNER_ID' => $dealId,
				'OWNER_TYPE' => \CCrmOwnerTypeAbbr::Deal,
				'PRODUCT_ID' => $sku->getId(),
				'PRODUCT_NAME' => $sku->getName(),
				'PRICE' => $price,
				'PRICE_ACCOUNT' => $price,
				'PRICE_EXCLUSIVE' => $price,
				'PRICE_NETTO' => $price,
				'PRICE_BRUTTO' => $price,
				'QUANTITY' => 1,
				'TYPE' => ProductType::TYPE_SERVICE,
			];
			$productRowId = \CCrmProductRow::Add($fields, false);
			if (!$productRowId)
			{
				//TODO: log error
				continue;
			}

			$skuProductMap[$sku->getId()] = ['productRowId' => $productRowId];
		}

		return $skuProductMap;
	}

	public function createDealForBooking(Booking $booking, int $userId): int|null
	{
		if (!$this->isAvailable())
		{
			return null;
		}

		// if userId is 0, then it's system action - allow to create deal
		if (
			$userId
			&& !Container::getInstance()
				->getUserPermissions($userId)
				->entityType()
				->canAddItems(CCrmOwnerType::Deal)
		)
		{
			return null;
		}

		$crmClient = $this->getCrmClientFromBooking($booking);
		$clientTypeCode = null;
		$clientId = null;
		if ($crmClient)
		{
			[$clientType, $clientId] = $crmClient;
			$clientTypeCode = $clientType->getCode();
		}

		return $this->createDeal($clientTypeCode, $clientId);
	}

	public function deleteProductsFromDeal(array $skuIds): void
	{
		if (!$this->isAvailable())
		{
			return;
		}

		foreach ($skuIds as $skuId)
		{
			\CCrmProductRow::Delete($skuId, false);
		}
	}

	private function isAvailable(): bool
	{
		return Loader::includeModule('crm');
	}

	private function getCrmClientFromBooking(Booking $booking): array|null
	{
		$client = $booking->getClientCollection()->getPrimaryClient();
		if (!$client)
		{
			return null;
		}
		$clientType = $client->getType();

		if (!($clientType && $clientType->getModuleId() === 'crm'))
		{
			return null;
		}

		$clientId = (int)$client->getId();
		$clientTypeCode = $clientType->getCode();
		if (!in_array($clientTypeCode, [CCrmOwnerType::CompanyName, CCrmOwnerType::ContactName], true))
		{
			return null;
		}

		return [$clientType, $clientId];
	}

	private function createDeal(string|null $clientTypeCode = null, int|null $clientId = null): int|null
	{
		/** @var Factory\Deal $factory */
		$factory = Container::getInstance()->getFactory(\CCrmOwnerType::Deal);
		if (!$factory)
		{
			return null;
		}

		$item = $factory->createItem()
			->setSourceId('BOOKING')
			->setTypeId('SERVICES')
		;

		if ($clientId)
		{
			match ($clientTypeCode)
			{
				CCrmOwnerType::CompanyName => $item->setCompanyId($clientId),
				CCrmOwnerType::ContactName => $item->setContactId($clientId),
				default => null,
			};
		}

		$addResult = $factory->getAddOperation($item)->disableCheckAccess()->launch();

		return $addResult->isSuccess() ? $item->getId() : null;
	}
}
