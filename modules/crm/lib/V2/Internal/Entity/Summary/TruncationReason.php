<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Entity\Summary;

/**
 * Why a section carries less than the source had.
 *
 * @internal
 */
enum TruncationReason: string
{
	case ItemLimit = 'item_limit';
	case LengthLimit = 'length_limit';

	/**
	 * A part of the section is out of the rights of the user, and what is left is not the whole of it.
	 *
	 * It is named before the limits of volume when both happened: a limit cuts the tail of a list of
	 * alike records, while the rights take out of it exactly what this user will not see anywhere -
	 * the coarser loss of the two.
	 */
	case RestrictedPart = 'restricted_part';

	/**
	 * The read stopped before the end of what it was reading, and the records below it were never
	 * reached - so what is answered is the recent part of the history and not the whole of it.
	 *
	 * It is not the ceiling of the answer: the ceiling is a number the caller names and may raise,
	 * while this is the depth a bounded read of an unbounded history walks.
	 */
	case ReadDepth = 'read_depth';
}
