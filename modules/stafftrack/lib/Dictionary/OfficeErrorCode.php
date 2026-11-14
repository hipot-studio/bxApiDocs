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

	/**
	 * The geo zone of the office being saved overlaps the zone of one already in the directory. The
	 * nearest of the overlapped travels with the refusal, so a client names the office it clashes with
	 * rather than the rule alone.
	 */
	public const GEO_ZONE_OVERLAP = 'STAFFTRACK_OFFICE_GEO_ZONE_OVERLAP';

	/**
	 * The card was changed by someone else since the caller read it, and nothing was written: the caller
	 * reads the office again rather than overwrite the change it has not seen.
	 */
	public const VERSION_CONFLICT = 'STAFFTRACK_OFFICE_VERSION_CONFLICT';

	/**
	 * The key a caller names its attempt to save a card by is not of the expected form, and nothing was
	 * written: without a key a repeat of a lost answer cannot be told from an edit of someone else.
	 */
	public const INVALID_SAVE_ATTEMPT_KEY = 'STAFFTRACK_OFFICE_INVALID_SAVE_ATTEMPT_KEY';

	/**
	 * The office the staff of a removed one was to go to is not in the directory, and nothing was
	 * removed: a client drops the transfer rather than tells the person the office they remove is gone.
	 */
	public const TRANSFER_TARGET_NOT_FOUND = 'STAFFTRACK_OFFICE_TRANSFER_TARGET_NOT_FOUND';

	/**
	 * The office of transfer is the removed office itself or a negative identifier, and nothing was
	 * removed. Refused by the input before any office is looked at.
	 */
	public const INVALID_TRANSFER_TARGET = 'STAFFTRACK_OFFICE_INVALID_TRANSFER_TARGET';

	/** The caller already sees the staff of the office, so there is nothing to request. */
	public const ACCESS_ALREADY_AVAILABLE = 'STAFFTRACK_OFFICE_ACCESS_ALREADY_AVAILABLE';

	/** The request of the caller to the office is still waiting for a decision. */
	public const ACCESS_REQUEST_PENDING = 'STAFFTRACK_OFFICE_ACCESS_REQUEST_PENDING';

	/** Nobody could decide on a request of the caller: no head and no administrator. */
	public const ACCESS_REQUEST_NO_RECIPIENTS = 'STAFFTRACK_OFFICE_ACCESS_REQUEST_NO_RECIPIENTS';

	/** The request could not be delivered to anybody, and nothing of it was kept. */
	public const ACCESS_REQUEST_UNAVAILABLE = 'STAFFTRACK_OFFICE_ACCESS_REQUEST_UNAVAILABLE';
}
