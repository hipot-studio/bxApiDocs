<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Integration\Main\EventHandler;

use Bitrix\Intranet\Internal\Service\Otp\OtpAuthSuccessAnalyticsService;

final class OtpAuthSuccessAnalytics
{
	private static ?OtpAuthSuccessAnalyticsService $service = null;

	public static function send(array $eventData): void
	{
		self::$service ??= new OtpAuthSuccessAnalyticsService();
		self::$service->send($eventData);
	}
}
