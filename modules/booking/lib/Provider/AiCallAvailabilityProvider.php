<?php

declare(strict_types=1);

namespace Bitrix\Booking\Provider;

use Bitrix\Booking\Internals\Container;

class AiCallAvailabilityProvider
{
	public static function isAvailable(): bool
	{
		return Container::getAiCallMessageSender()->canUse();
	}
}
