<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Entity\Summary;

/**
 * What a summary section has to say about itself.
 *
 * `Empty` and `Restricted` are told apart on purpose: the first means the data is there to read and
 * there is none of it, the second that the user may not read it at all. A consumer that merges them
 * turns a missing right into an honest "nothing happened".
 *
 * @internal
 */
enum SectionState: string
{
	case Filled = 'filled';
	case Empty = 'empty';
	case Restricted = 'restricted';
}
