<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Enum\Otp;

enum PhoneConfirmationTerminalStatus: string
{
	case ConfirmedByCall = 'confirmed_by_call';
	case ConfirmedBySms = 'confirmed_by_sms';
}
