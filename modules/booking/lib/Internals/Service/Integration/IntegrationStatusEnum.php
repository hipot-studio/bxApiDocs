<?php

declare(strict_types=1);

namespace Bitrix\Booking\Internals\Service\Integration;

enum IntegrationStatusEnum: string
{
	case NotConnected = 'not_connected';
	case Connected = 'connected';
	case InProgress = 'in_progress';
}
