<?php

declare(strict_types=1);

namespace Bitrix\Booking\Internals\Service\Notifications\Analytics;

use Bitrix\Booking\Internals\Service\Notifications\Entity\BookingMessage;
use Bitrix\Main\Analytics\AnalyticsEvent;

class AiCallAnalytics
{
	private const ANALYTICS_EVENT = 'call_parsing_booking';
	private const ANALYTICS_TOOL = 'booking';
	private const ANALYTICS_CATEGORY = 'ai_operations';

	public function sendSuccess(BookingMessage $bookingMessage): void
	{
		$this->send($bookingMessage, AnalyticsEvent::STATUS_SUCCESS);
	}

	public function sendError(BookingMessage $bookingMessage): void
	{
		$this->send($bookingMessage, AnalyticsEvent::STATUS_ERROR);
	}

	private function send(BookingMessage $bookingMessage, string $status): void
	{
		$notificationType = $bookingMessage->getNotificationType();
		if ($notificationType === null)
		{
			return;
		}

		$event = (new AnalyticsEvent(
			self::ANALYTICS_EVENT,
			self::ANALYTICS_TOOL,
			self::ANALYTICS_CATEGORY,
		))
			->setType($notificationType->value)
			->setStatus($status)
		;

		$this->sendEvent($event);
	}

	protected function sendEvent(AnalyticsEvent $event): void
	{
		$event->send();
	}
}
