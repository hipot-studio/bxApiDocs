<?php

declare(strict_types=1);

namespace Bitrix\Booking\Internals\Service\Promotion\AiCallBanner;

enum AiCallBannerMode: string
{
	case Invitation = 'invitation';
	case AutoSwitched = 'auto_switched';
}
