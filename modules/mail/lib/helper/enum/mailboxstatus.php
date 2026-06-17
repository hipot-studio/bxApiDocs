<?php

declare(strict_types=1);

namespace Bitrix\Mail\Helper\Enum;

enum MailboxStatus: string
{
	case Inactive = 'N';
	case Active = 'Y';
	case Pending = 'P';
	case Canceled = 'C';
}
