<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Message\BlocksBuilder\Entity\Blocks\Field\Footer;

/**
 * Sizes a footer chip may be given. The set follows the application, which draws two of them, and
 * is spelled the way the application spells them: the value goes into the native payload as it is.
 * The web chip knows four sizes and spells these two the same way, but the web maps them by a pair
 * of its own all the same: the spelling of a design system is its own business.
 *
 * A size outside the set is refused on send rather than corrected, for the same reason a chip design
 * is: the sender asked for a look nobody confirmed. Widening the set is always possible; narrowing a
 * released one is not.
 *
 * Sm is what a chip is drawn in when the sender names no size - the size the application has been
 * drawing footer chips in all along.
 */
enum ChipSize: string
{
	case Sm = 's';
	case Md = 'm';
}
