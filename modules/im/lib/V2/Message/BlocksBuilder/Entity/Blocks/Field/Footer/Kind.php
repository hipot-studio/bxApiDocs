<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Message\BlocksBuilder\Entity\Blocks\Field\Footer;

enum Kind: string
{
	case Button = 'button';
	case Chip = 'chip';
}
