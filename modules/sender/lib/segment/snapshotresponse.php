<?php
/**
 * Bitrix Framework
 * @package bitrix
 * @subpackage sender
 * @copyright 2001-2026 Bitrix
 */

namespace Bitrix\Sender\Segment;

/**
 * Vocabulary of the answers the segment form gets about the snapshot of its segment.
 *
 * The values cross the wire to the client script as they are written here, so they are named
 * once and read from here on both sides of the component.
 */
final class SnapshotResponse
{
	/** A build of the snapshot was started: the counters arrive over the pull channel, not in this answer. */
	public const KEY_WAITING = 'waiting';

	/** The snapshot is already being built, or another force holds the lock of the segment. */
	public const REFUSED_BUILDING = 'SEGMENT_BUILDING';

	/** A mailing queue record holds the segment. */
	public const REFUSED_IN_QUEUE = 'SEGMENT_IN_QUEUE';

	/** No connector of the segment materializes a snapshot, so there is nothing to rebuild. */
	public const REFUSED_NOTHING_TO_REBUILD = 'SEGMENT_NOTHING_TO_REBUILD';
}
