<?php

declare(strict_types=1);

namespace Bitrix\Booking\Internals\Service\Notifications\MessageSender;

use Bitrix\Booking\Entity\Booking\Booking;
use Bitrix\Booking\Entity\Resource\Resource;
use Bitrix\Booking\Internals\Container;
use Bitrix\Booking\Internals\Service\Feature\BookingConfirmContext;
use Bitrix\Booking\Internals\Service\Feature\BookingConfirmLink;
use Bitrix\Main\Context;
use Bitrix\Main\Localization\Loc;
use DateTime;
use DateTimeImmutable;

Loc::loadMessages($_SERVER['DOCUMENT_ROOT'] . BX_ROOT . '/modules/booking/lib/Internals/Service/Notifications/BookingMessageCreator.php');

class BookingDataExtractor
{
	private Context\Culture|null $culture = null;

	public function __construct()
	{
		$this->culture = Context::getCurrent()->getCulture();
	}

	public function getClientName(Booking $booking): string
	{
		$primaryClient = $booking->getPrimaryClient();
		if (!$primaryClient)
		{
			return '';
		}

		return $primaryClient->getName();
	}

	public function getResource(Booking $booking): Resource|null
	{
		return $booking->getPrimaryResource();
	}

	public function getResourceName(Booking $booking): string
	{
		$resource = $this->getResource($booking);
		if (!$resource)
		{
			return '';
		}

		return $resource->getName() ?? '';
	}

	public function getResourceTypeName(Booking $booking): string
	{
		$resource = $this->getResource($booking);
		if (!$resource)
		{
			return '';
		}

		$resourceType = $resource->getType();
		if (!$resourceType)
		{
			return '';
		}

		return $resourceType->getName() ?? '';
	}

	public function getManagerName(Booking $booking): string
	{
		$defaultManagerName = Loc::getMessage('BOOKING_NOTIFICATION_MANAGER_DEFAULT_NAME') ?? ' ';

		$managerId = $booking->getCreatedBy();
		if (!$managerId)
		{
			return $defaultManagerName;
		}

		$user = \CUser::getById($managerId)->fetch();
		if (!$user)
		{
			return $defaultManagerName;
		}

		return $user['NAME'] ?: $defaultManagerName;
	}

	public function getDateFrom(Booking $booking): string
	{
		$dateFrom = $booking->getDatePeriod()?->getDateFrom();
		if (!$dateFrom)
		{
			return '';
		}

		return $this->getDayMonthFormattedDateTime($dateFrom);
	}

	public function getDateTo(Booking $booking): string
	{
		$dateTo = $booking->getDatePeriod()?->getDateTo();
		if (!$dateTo)
		{
			return '';
		}

		return $this->getDayMonthFormattedDateTime($dateTo);
	}

	public function getDateTimeFrom(Booking $booking): string
	{
		$dateFrom = $booking->getDatePeriod()?->getDateFrom();
		if (!$dateFrom)
		{
			return '';
		}

		return implode(
			' ',
			[
				$this->getShortTimeFormattedDateTime($dateFrom),
				$this->getDayMonthFormattedDateTime($dateFrom),
			]
		);
	}

	public function getDateTimeTo(Booking $booking): string
	{
		$dateTo = $booking->getDatePeriod()?->getDateTo();
		if (!$dateTo)
		{
			return '';
		}

		return implode(
			' ',
			[
				$this->getShortTimeFormattedDateTime($dateTo),
				$this->getDayMonthFormattedDateTime($dateTo),
			]
		);
	}

	public function getCompanyName(): string
	{
		$myCrmCompanyName = Container::getMyCompanyProvider()->getName();
		if ($myCrmCompanyName)
		{
			return $myCrmCompanyName;
		}

		/**
		 * We need to keep a space here so that to match EDNA templates containing company name variable
		 */

		return ' ';
	}

	public function getConfirmationLink(Booking $booking): string
	{
		return (new BookingConfirmLink())->getLink($booking);
	}

	public function getDelayedConfirmationLink(Booking $booking): string
	{
		return (new BookingConfirmLink())->getLink($booking, BookingConfirmContext::Delayed);
	}

	public function getFeedbackLink(): string
	{
		//@todo
		return '';
	}

	public function getServices(Booking $booking): string
	{
		$serviceNames = [];

		$bookingSkuCollection = $booking->getSkuCollection();
		foreach ($bookingSkuCollection as $sku)
		{
			$skuName = $sku->getName();
			if (!$skuName)
			{
				continue;
			}

			$serviceNames[] = $sku->getName();
		}

		/**
		 * We need to keep a space here so that to match EDNA templates containing services variable
		 */
		$nonEmptyValue = ' ';

		return empty($serviceNames) ? $nonEmptyValue : implode(', ', $serviceNames);
	}

	private function getCultureFormat(string $formatCode): string
	{
		if (!$this->culture)
		{
			return '';
		}

		$format = $this->culture->get($formatCode);

		return $format ?? '';
	}

	private function getDayMonthFormattedDateTime(DateTimeImmutable $dateTime): string
	{
		return $this->formatDateTime(
			$dateTime,
			$this->getCultureFormat('DAY_MONTH_FORMAT')
		);
	}

	private function getShortTimeFormattedDateTime(DateTimeImmutable $dateTime): string
	{
		return $this->formatDateTime(
			$dateTime,
			$this->getCultureFormat('SHORT_TIME_FORMAT')
		);
	}

	private function formatDateTime(DateTimeImmutable $dateTime, string $format): string
	{
		$userTimezoneOffset = $dateTime->getTimezone()->getOffset(new DateTime());
		$serverTimezoneOffset = (new DateTime())->getTimezone()->getOffset(new DateTime());

		return FormatDate(
			$format,
			$dateTime->getTimestamp() + ($userTimezoneOffset - $serverTimezoneOffset)
		);
	}
}
