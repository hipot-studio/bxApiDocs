<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Dto\Audit;

enum AuditState: string
{
	case Ok = 'ok';
	case Empty = 'empty';
	case Disabled = 'disabled';
	case LicenseUnavailable = 'license_unavailable';
	case AccessDenied = 'access_denied';
	case TemporaryError = 'temporary_error';
}
