<?php

declare(strict_types=1);

namespace Bitrix\Booking\Internals\Integration\Im;

use Bitrix\Booking\Entity\Booking\Booking;
use Bitrix\Main\Error;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;
use Bitrix\Main\Context;
use DateTimeImmutable;
use CTimeZone;

Loc::loadMessages($_SERVER['DOCUMENT_ROOT'] . BX_ROOT . '/modules/booking/lib/Integration/Im/Chat.php');

class Chat
{
	public function onBookingCanceled(Booking $booking): Result
	{
		$toUserId = $booking->getCreatedBy();
		if (!$toUserId)
		{
			return (new Result())->addError(new Error('User is not specified'));
		}

		$title = static fn (?string $languageId = null) => Loc::getMessage(
			'BOOKING_IM_SYSTEM_NOTIFICATION_MESSAGE_TITLE',
			[],
			$languageId
		);

		$clientData = $booking->getPrimaryClient()?->getData() ?? null;
		$clientName = $clientData['name'] ?? '';
		$clientUrl = $booking->getPrimaryClientUrl();

		$message = fn (?string $languageId = null) => Loc::getMessage(
			'BOOKING_IM_SYSTEM_NOTIFICATION_ON_BOOKING_CANCELED_MSG_VER_1',
			[
				'#BOOKING_DATE#' => $this->getDateFormatted(
					$toUserId,
					$booking->getDatePeriod()?->getDateFrom(),
					$languageId
				),
				'#CLIENT_NAME#' => $clientName,
				'#CLIENT_URL#' => $clientUrl,
				'#BOOKING_URL#' => '/booking/detail/' . $booking->getId() . '/',
			],
			$languageId
		);

		return $this->sendSystemNotification(
			toUserId: $booking->getCreatedBy(),
			notifyTag: 'BOOKING|CANCELED|' . $booking->getId(),
			notifyEvent: 'info',
			titleFn: $title,
			messageFn: $message,
		);
	}

	private function sendSystemNotification(
		int $toUserId,
		string $notifyTag,
		string $notifyEvent,
		callable $titleFn,
		callable $messageFn,
	): Result
	{
		if (!Loader::includeModule('im'))
		{
			return new Result();
		}

		$params = [
			'NOTIFY_MODULE' => 'booking',
			'TITLE' => $titleFn,
			'TO_USER_ID' => $toUserId,
			'NOTIFY_EVENT' => $notifyEvent,
			'NOTIFY_TAG' => $notifyTag,
			'MESSAGE' => $messageFn,
			'MESSAGE_OUT' => $messageFn,
		];

		return \CIMNotify::Add($params) !== false
			? new Result()
			: (new Result())->addError(new Error('sendSystemNotification failed'))
			;
	}

	private function getDateFormatted(
		int $userId,
		DateTimeImmutable|null $date,
		string|null $languageId = null,
	): string
	{
		if (!$date)
		{
			return '';
		}

		$timestamp = $date->getTimestamp() + CTimeZone::getOffset($userId);
		$culture = Context::getCurrent()?->getCulture();

		return (
			FormatDate($culture?->getFullDateFormat(), $timestamp, false, $languageId)
			. ' ' . FormatDate($culture?->getShortTimeFormat(), $timestamp, false, $languageId)
		);
	}
}
