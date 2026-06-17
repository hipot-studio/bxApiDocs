<?php

declare(strict_types=1);

namespace Bitrix\Booking\Internals\Service\AiAssistant\Mapper;

use Bitrix\Booking\Entity\Booking\Booking;
use Bitrix\Booking\Internals\Service\AiAssistant\DateTimeService;

class BookingMapper
{
	public function __construct(
		private readonly ResourceMapper $resourceMapper,
		private readonly SkuMapper $skuMapper,
		private readonly DateTimeService $dateTimeService,
	)
	{
	}

	public function mapFromEntity(Booking $booking): array
	{
		$primaryResource = $booking->getResourceCollection()->getPrimary();

		$services = [];
		foreach ($booking->getSkuCollection() as $skuItem)
		{
			$services[] = $this->skuMapper->mapFromEntity($skuItem);
		}

		return [
			'id' => $booking->getId(),
			'clientName' => $booking->getClientCollection()->getPrimaryClient()?->getName(),
			'dateTime' => $this->dateTimeService->formatDateTime(
				$booking->getDatePeriod()->getDateFrom(),
			),
			'resource' => $primaryResource
				? $this->resourceMapper->mapFromEntity($primaryResource)
				: null,
			'services' => $services,
		];
	}
}
