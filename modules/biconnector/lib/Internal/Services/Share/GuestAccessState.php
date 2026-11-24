<?php

declare(strict_types=1);

namespace Bitrix\BIConnector\Internal\Services\Share;

/**
 * External state a guest sees instead of a report. Internal error codes stay as they are:
 * only the codes listed here mean the link itself is dead, everything else — including an unknown
 * or a newly added code — is a temporary failure and must not tell the guest to ask for a new link.
 */
enum GuestAccessState: string
{
	case LinkInvalid = 'LINK_INVALID';
	case ReportUnavailable = 'REPORT_UNAVAILABLE';

	public static function fromErrorCode(?string $code): self
	{
		return match ($code)
		{
			'SHARE_INVALID',
			'SHARE_UNAVAILABLE',
			'DASHBOARD_NOT_FOUND' => self::LinkInvalid,

			default => self::ReportUnavailable,
		};
	}
}
