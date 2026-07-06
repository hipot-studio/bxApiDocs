<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Public\Message\BlocksBuilder\Entity;

enum ListIconType: string
{
	case Arrow = 'arrow';
	case Bullet = 'bullet';
	case Search = 'search';
}
