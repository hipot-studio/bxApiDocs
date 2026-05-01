<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Service\Notification;

interface NotificationService
{
	public function isAvailable(): bool;

	public function needToShow(): bool;
}
