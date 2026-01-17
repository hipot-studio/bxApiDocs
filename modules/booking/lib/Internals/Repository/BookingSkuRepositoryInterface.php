<?php

declare(strict_types=1);

namespace Bitrix\Booking\Internals\Repository;

use Bitrix\Booking\Entity\Booking\BookingSkuCollection;

interface BookingSkuRepositoryInterface
{
	public function link(int $bookingId, BookingSkuCollection $skuCollection): void;
	public function unLink(int $bookingId, BookingSkuCollection $skuCollection): void;
	public function checkExistence(array $filter): bool;
	public function update(int $bookingId, array $skuProductRowMap): void;
	/**
	 * @param int[] $skuIds
	 * @return int[]
	 */
	public function getUsedIds(array $skuIds): array;
}
