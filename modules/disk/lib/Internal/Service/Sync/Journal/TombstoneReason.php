<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\Sync\Journal;

/**
 * Why an object left the sight of the addressee of a tombstone.
 *
 * The reason is not a second copy of the operation: a tombstone always means the same thing to a
 * client - drop what you hold - and the reason is what tells support and the client apart afterwards,
 * the object being gone from the portal against the addressee alone having lost sight of it.
 *
 * The first two belong to a tombstone over the whole audience of an object and are published when the
 * last source of its visibility disappears. The last two belong to an addressed tombstone: nothing
 * happened to the object, and only the interaction that carried it to one user did.
 */
enum TombstoneReason: string
{
	case Deleted = 'deleted';
	case AccessLost = 'access_lost';
	case InteractionAged = 'interaction_aged';
	case InteractionRemoved = 'interaction_removed';
}
