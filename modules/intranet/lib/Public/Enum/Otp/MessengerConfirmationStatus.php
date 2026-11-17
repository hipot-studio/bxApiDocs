<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Public\Enum\Otp;

enum MessengerConfirmationStatus: string
{
	case Pending = 'pending';
	case Confirmed = 'confirmed';
	case Declined = 'declined';
	case Expired = 'expired';
	case Cancelled = 'cancelled';

	public function isTerminal(): bool
	{
		return $this !== self::Pending;
	}
}
