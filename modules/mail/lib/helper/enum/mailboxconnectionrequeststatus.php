<?php

declare(strict_types=1);

namespace Bitrix\Mail\Helper\Enum;

enum MailboxConnectionRequestStatus: string
{
	case Pending = 'pending';
	case Connected = 'connected';
	case Rejected = 'rejected';
	case Cancelled = 'cancelled';

	/**
	 * @return string[]
	 */
	public static function values(): array
	{
		return array_column(self::cases(), 'value');
	}
}
