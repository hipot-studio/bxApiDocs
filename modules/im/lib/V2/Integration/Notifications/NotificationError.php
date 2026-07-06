<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Integration\Notifications;

use Bitrix\Im\V2\Error;

class NotificationError extends Error
{
	public const SERVICE_UNAVAILABLE = 'NOTIFICATION_SERVICE_UNAVAILABLE';
}
