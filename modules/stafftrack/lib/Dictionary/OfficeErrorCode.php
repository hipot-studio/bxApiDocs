<?php

namespace Bitrix\StaffTrack\Dictionary;

/**
 * The wire codes of the office directory that more than one layer answers with: the commands of the
 * card and the service of the photos refuse for the same two reasons, and a client tells one refusal
 * from another by the value alone. Declared once so that renaming one renames it everywhere.
 */
final class OfficeErrorCode
{
	public const ACCESS_DENIED = 'STAFFTRACK_OFFICE_ACCESS_DENIED';
	public const NOT_FOUND = 'STAFFTRACK_OFFICE_NOT_FOUND';

	/**
	 * The office is held by another operation over the office itself, and the answer comes before any
	 * write: a client is told to come back rather than told that nothing was saved.
	 */
	public const BUSY = 'STAFFTRACK_OFFICE_BUSY';
}
