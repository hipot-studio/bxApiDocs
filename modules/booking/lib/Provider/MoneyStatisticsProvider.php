<?php

declare(strict_types=1);

namespace Bitrix\Booking\Provider;

use Bitrix\Booking\Entity;
use Bitrix\Booking\Internals\Container;
use Bitrix\Booking\Internals\Repository\BookingRepositoryInterface;
use Bitrix\Booking\Provider\Params\Booking\BookingFilter;
use Bitrix\Booking\Provider\Params\Booking\BookingSelect;
use DateTimeImmutable;

class MoneyStatisticsProvider
{
	private BookingRepositoryInterface $bookingRepository;

	public function __construct()
	{
		$this->bookingRepository = Container::getBookingRepository();
	}

	public function get(int $userId): array
	{
		$firstDateOfThisMonth = (new DateTimeImmutable('first day of this month'))->setTime(0, 0, 0);
		$lastDateOfThisMonth = (new DateTimeImmutable('last day of this month'))->setTime(23, 59, 59);

		$todayStart = (new DateTimeImmutable('today'))->setTime(0, 0, 0);
		$todayEnd = (new DateTimeImmutable('today'))->setTime(23, 59, 59);

		$monthBookings = $this->bookingRepository->getList(
			filter: new BookingFilter([
				'WITHIN' => [
					'DATE_FROM' => $firstDateOfThisMonth->getTimestamp() - \CTimeZone::GetOffset(),
					'DATE_TO' => $lastDateOfThisMonth->getTimestamp() - \CTimeZone::GetOffset(),
				],
				'VISIT_STATUS' => [
					Entity\Booking\BookingVisitStatus::Visited->value,
					Entity\Booking\BookingVisitStatus::Unknown->value,
				],
			]),
			select: (new BookingSelect(['SKUS']))->prepareSelect(),
			userId: $userId,
		);
		$this->bookingRepository->withSkus($monthBookings);

		$todayBookings = $this->bookingRepository->getList(
			filter: new BookingFilter([
				'WITHIN' => [
					'DATE_FROM' => $todayStart->getTimestamp() - \CTimeZone::GetOffset(),
					'DATE_TO' => $todayEnd->getTimestamp() - \CTimeZone::GetOffset(),
				],
				'VISIT_STATUS' => [
					Entity\Booking\BookingVisitStatus::Visited->value,
					Entity\Booking\BookingVisitStatus::Unknown->value,
				],
			]),
			select: (new BookingSelect(['SKUS']))->prepareSelect(),
			userId: $userId,
		);
		$this->bookingRepository->withSkus($todayBookings);

		return [
			'today' => $this->getMoneyStatistics($todayBookings->getSkuCollection()),
			'month' => $this->getMoneyStatistics($monthBookings->getSkuCollection()),
		];
	}

	private function getMoneyStatistics(Entity\Sku\SkuCollection $skuCollection): array
	{
		$result = [];

		foreach ($skuCollection as $sku)
		{
			$currencyId = $sku->getCurrencyId();

			if (!isset($result[$currencyId]))
			{
				$result[$currencyId] = [
					'opportunity' => 0,
					'currencyId' => $currencyId,
				];
			}

			$result[$currencyId]['opportunity'] += $sku->getPrice();
		}

		return array_values(
			array_map(static fn ($item): array => [
				'currencyId' => $item['currencyId'],
				'opportunity' => $item['opportunity'],
			], $result)
		);
	}
}
