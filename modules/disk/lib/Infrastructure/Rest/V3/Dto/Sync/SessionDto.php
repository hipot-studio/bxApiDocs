<?php

declare(strict_types=1);

namespace Bitrix\Disk\Infrastructure\Rest\V3\Dto\Sync;

use Bitrix\Main\Type\DateTime;
use Bitrix\Rest\V3\Dto\Dto;

/**
 * The session of synchronisation as the client sees it: where to start reading from, until when,
 * and how much there is.
 *
 * Built by hand from what the service has just fixed rather than mapped from the stored row, so it
 * declares neither an ORM entity nor a mapper. Property order is the order of the published answer.
 */
final class SessionDto extends Dto
{
	public int $sessionId;

	/** The position of the initial fetch, opaque to the client. */
	public string $snapshotToken;

	/**
	 * The position in the journal of changes, opaque to the client. It is handed out together with
	 * the session because the two are read in turn from the very beginning: what the fetch does not
	 * reach, the journal already keeps.
	 */
	public string $cursor;

	/**
	 * When the token of the initial fetch stops being accepted. Not the expiry of the cursor: the
	 * cursor lives as long as the journal keeps its records, and outlives the session it came with.
	 */
	public DateTime $snapshotTokenExpiresAt;

	/**
	 * The depth the portal actually applies, always answered - it may be shorter than the one asked
	 * for, and handing over less than was asked for without saying so is what this field prevents.
	 */
	public int $effectiveHistoryDepthDays;

	/**
	 * A lower bound of what the initial fetch is about to deliver, together with whether that bound
	 * is the whole of it.
	 *
	 * Both are left uninitialised - and so are absent from the answer entirely, not present as null
	 * - when no storage of the user could be counted without checking rights per object. A client
	 * has to be able to tell "the portal cannot estimate this cheaply" from "there is nothing", and
	 * a zero would say the second.
	 */
	public ?int $approximateObjectCount;

	public ?bool $approximateObjectCountComplete;
}
