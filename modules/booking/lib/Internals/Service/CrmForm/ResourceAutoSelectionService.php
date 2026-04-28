<?php

declare(strict_types=1);

namespace Bitrix\Booking\Internals\Service\CrmForm;

use Bitrix\Booking\Entity\Booking\Booking;
use Bitrix\Booking\Entity\Booking\BookingCollection;
use Bitrix\Booking\Entity\DatePeriod;
use Bitrix\Booking\Entity\Resource\ResourceCollection;
use Bitrix\Booking\Internals\Service\FreeTime\NearestDateSlotsHandler;
use Bitrix\Booking\Internals\Service\FreeTime\NearestDateSlotsRequest;

class ResourceAutoSelectionService
{
	public function search(
		DatePeriod $searchPeriod,
		ResourceCollection $resourceCollection,
		BookingCollection $bookingCollection,
	): ResourceAutoSelectionSearchResult
	{
		$bestResourceId = null;
		$bestDate = null;
		$bestSlots = null;

		/** @var Resource $resource */
		foreach ($resourceCollection as $resource)
		{
			$resourceId = $resource->getId();

			$resourceBookings = $bookingCollection->filter(
				static function(Booking $booking) use ($resourceId)
				{
					return in_array(
						$resourceId,
						$booking->getResourceCollection()->getEntityIds(),
						true
					);
				}
			);

			$datePeriodCollection = (new NearestDateSlotsHandler())(
				new NearestDateSlotsRequest(
					$resource->getSlotRanges(),
					$resourceBookings,
					$searchPeriod,
				)
			);
			if ($datePeriodCollection->isEmpty())
			{
				continue;
			}

			$date = $datePeriodCollection->getFirstCollectionItem()->getDateFrom()->format('Y-m-d');
			$slotsCnt = $datePeriodCollection->count();
			if (
				$bestResourceId === null
				|| (
					strtotime($date) < strtotime($bestDate)
					|| (
						strtotime($date) === strtotime($bestDate)
						&& $slotsCnt > $bestSlots
					)
				)
			)
			{
				$bestResourceId = $resourceId;
				$bestDate = $date;
				$bestSlots = $slotsCnt;
			}
		}

		return new ResourceAutoSelectionSearchResult($bestResourceId, $bestDate);
	}
}
