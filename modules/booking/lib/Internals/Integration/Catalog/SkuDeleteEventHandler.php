<?php

declare(strict_types=1);

namespace Bitrix\Booking\Internals\Integration\Catalog;

use Bitrix\Booking\Internals\Container;
use Bitrix\Booking\Internals\Repository\BookingSkuRepositoryInterface;
use Bitrix\Booking\Internals\Repository\ORM\ResourceSkuRepository;
use Bitrix\Main\Localization\Loc;

class SkuDeleteEventHandler
{
	private ResourceSkuRepository $resourceSkuRepository;
	private BookingSkuRepositoryInterface $bookingSkuRepository;

	public function __construct()
	{
		$this->resourceSkuRepository = Container::getResourceSkuRepository();
		$this->bookingSkuRepository = Container::getBookingSkuRepository();
	}

	public static function onBeforeIBlockElementDelete($productId): bool
	{
		$productId = (int)$productId;
		if ($productId < 0)
		{
			return true;
		}

		return (new self())->checkRelations($productId);
	}

	private function checkRelations(int $productId): bool
	{
		$hasRelationToResources = $this->hasRelationToResources($productId);
		if ($hasRelationToResources)
		{
			$this->throwError();

			return false;
		}

		$hasRelationToBooking = $this->hasRelationToBooking($productId);
		if ($hasRelationToBooking)
		{
			$this->throwError();

			return false;
		}

		return true;
	}

	private function hasRelationToResources(int $productId): bool
	{
		return $this->resourceSkuRepository->checkExistence(['SKU_ID' => $productId]);
	}

	private function hasRelationToBooking(int $productId): bool
	{
		return $this->bookingSkuRepository->checkExistence(['SKU_ID' => $productId]);
	}

	private function throwError(): void
	{
		global $APPLICATION;

		$error = Loc::getMessage('BOOKING_CATALOG_INTEGRATION_ERROR_PRODUCT_RELATION_EXISTS');
		$APPLICATION->ThrowException($error);
	}
}
