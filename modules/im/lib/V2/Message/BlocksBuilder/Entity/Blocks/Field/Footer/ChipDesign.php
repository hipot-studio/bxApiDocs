<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Message\BlocksBuilder\Entity\Blocks\Field\Footer;

/**
 * Designs a footer chip may be given. The set is deliberately narrower than what the clients can
 * draw - the mobile map knows eighteen of them, the web chip all of them - and holds only what was
 * checked on both themes. A design outside the set is refused on send rather than corrected: the
 * sender asked for a look nobody confirmed, and a silent correction would hide that from them.
 * Widening the set is always possible; narrowing a released one is not.
 */
enum ChipDesign: string
{
	case Filled = 'FILLED';
	case FilledBitrixGpt = 'FILLED_BITRIX_GPT';
	case Tinted = 'TINTED';
	case Outline = 'OUTLINE';
}
