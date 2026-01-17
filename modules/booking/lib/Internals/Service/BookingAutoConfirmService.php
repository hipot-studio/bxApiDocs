<?php

declare(strict_types=1);

namespace Bitrix\Booking\Internals\Service;

use Bitrix\Booking\Entity\Booking\Booking;
use Bitrix\Booking\Entity\Resource\Resource;
use Bitrix\Booking\Internals\Exception\Booking\ConfirmBookingException;

class BookingAutoConfirmService
{
	public function shouldAutoConfirm(Booking $booking): bool
	{
		if (
			!($primaryResource = $booking->getPrimaryResource())
			|| !($startFrom = $booking->getDatePeriod()?->getDateFrom())
		)
		{
			throw new ConfirmBookingException('Start date and primary resource are required');
		}

		$startFromWithConfirmedPeriod = $startFrom->sub($this->getAutoConfirmPeriod($primaryResource));

		return (new \DateTimeImmutable())->getTimestamp() > $startFromWithConfirmedPeriod->getTimestamp();
	}

	private function getAutoConfirmPeriod(Resource $resource): \DateInterval
	{
		if ($resource->isConfirmationNotificationOn())
		{
			return new \DateInterval('PT' . $resource->getConfirmationNotificationDelay() . 'S');
		}

		// booking is auto confirmed if startFrom < auto_confirm_period (default is 1 day)
		$minutes = ModuleOptions::getDefaultAutoConfirmPeriodMinutes();

		return new \DateInterval('PT' . $minutes . 'M');
	}
}
