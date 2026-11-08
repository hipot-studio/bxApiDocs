<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Access\Activity;

/**
 * What a reader does with the activities of its set the user may not read.
 *
 * TWO MODES AND NOTHING IN BETWEEN, the same two the Item provider offers
 * ({@see \Bitrix\Crm\V2\Public\Provider\Item\AccessMode}): drop what may not be read, or keep its place and
 * show nothing of it. A reader that shows a list picks the second and keeps the shape of the page; a reader
 * that answers a question about the data itself picks the first.
 *
 * AN ENUM OF ITS OWN rather than the one of the Item provider: that one is published, belongs to the Item
 * provider and speaks of items. This one stays inside the domain of activities until the domain itself is
 * published.
 *
 * The split of a set by this mode is {@see ActivityAccess::readable()}.
 *
 * @internal
 */
enum AccessMode: string
{
	/**
	 * An activity the user may not read is left out of the result entirely: it is not in the set and its
	 * absence is all the reader says about it.
	 */
	case Filter = 'filter';

	/**
	 * An activity the user may not read keeps its place in the result as a {@see RestrictedActivityStub} -
	 * its id and the fact that it is out of reach, and nothing else. For cards, timelines and lists that
	 * need a placeholder instead of a hole.
	 */
	case Restricted = 'restricted';
}
