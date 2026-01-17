<?php

declare(strict_types=1);

namespace Bitrix\Booking\Internals\Integration\Catalog;

use Bitrix\Booking\Internals\Container;
use Bitrix\Main\Error;
use Bitrix\Main\Event;
use Bitrix\Main\EventResult;
use Bitrix\Main\Localization\Loc;

class SkuTypeChangeEventHandler
{
	private const MODULE_ID = 'booking';

	public static function onBeforeConvertProductsType(Event $event): EventResult
	{
		$result = new EventResult(type: EventResult::SUCCESS, moduleId: self::MODULE_ID);
		$productIds = $event->getParameter('PRODUCT_IDS');
		if (empty($productIds))
		{
			return $result;
		}

		$skus = Container::getCatalogServiceSkuProvider()->get($productIds);
		if (empty($skus))
		{
			return $result;
		}

		$skuIds = array_map(
			static fn (Sku $sku) => $sku->getId(),
			$skus
		);

		$usedSkuIds = self::getRelatedSkuIds($skuIds);
		if (empty($usedSkuIds))
		{
			return $result;
		}

		$names = [];
		foreach ($skus as $sku)
		{
			if (in_array($sku->getId(), $usedSkuIds, true))
			{
				$names[] = $sku->getName();
			}
		}

		return new EventResult(
			type: EventResult::ERROR,
			parameters: [
				'ERROR_PRODUCT_IDS' => $usedSkuIds,
				'ERROR' => new Error(
					message: Loc::getMessage('BOOKING_CATALOG_INTEGRATION_SKU_TYPE_CHANGE_ERROR', [
						'#NAMES#' => implode(', ', $names),
					]),
				),
			],
			moduleId: 'booking',
		);
	}

	private static function getRelatedSkuIds(array $skuIds): array
	{
		$bookingUsedIds = Container::getBookingSkuRepository()->getUsedIds($skuIds);
		$resourceUsedIds = Container::getResourceSkuRepository()->getUsedIds($skuIds);
		$resourceYandexUsedIds = Container::getResourceSkuYandexRepository()->getUsedIds($skuIds);

		return array_unique(array_merge($resourceUsedIds, $bookingUsedIds, $resourceYandexUsedIds));
	}
}
