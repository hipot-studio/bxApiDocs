<?php

namespace Bitrix\StaffTrack\Dictionary;

/**
 * The wire codes of the presence signal of an employee. They live apart from {@see OfficeErrorCode}
 * because the signal says nothing about the card of an office: it is refused on account of the portal
 * or of the point itself, never of the directory.
 */
final class OfficePresenceErrorCode
{
	public const DISABLED = 'STAFFTRACK_OFFICE_PRESENCE_SIGNAL_DISABLED';
	public const INVALID_POINT = 'STAFFTRACK_OFFICE_PRESENCE_INVALID_POINT';

	// Same value as CheckIn\AbstractCommand::ERROR_GEO_ENCRYPTION_UNAVAILABLE on purpose: the client already reads it.
	public const GEO_ENCRYPTION_UNAVAILABLE = 'STAFFTRACK_CHECK_IN_GEO_ENCRYPTION_UNAVAILABLE';
}
