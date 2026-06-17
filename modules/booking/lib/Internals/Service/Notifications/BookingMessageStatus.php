<?php

declare(strict_types=1);

namespace Bitrix\Booking\Internals\Service\Notifications;

enum BookingMessageStatus: string
{
	case Pending = 'pending';
	case Success = 'success';
	case Failed = 'failed';
	case Cancelled = 'cancelled';
}
