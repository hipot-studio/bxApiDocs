<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\UnifiedLink\Render;

enum ErrorPageState: string
{
	case HtmlSessionBusy = 'html-session-busy';
	case AccessDenied = 'access-denied';
	case EditFormatNotSupported = 'edit-format-not-supported';
	case EditNotSupported = 'edit-not-supported';
}
