<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Message\BlocksBuilder\Entity\Blocks\Field\Buttons;

enum Design: string
{
	case Filled = 'FILLED';
	case OutlineAccent2 = 'OUTLINE_ACCENT_2';
	case PlainNoAccent = 'PLAIN_NO_ACCENT';
}
