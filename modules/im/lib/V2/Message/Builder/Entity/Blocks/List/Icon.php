<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Message\Builder\Entity\Blocks\List;

enum Icon: string
{
	case Number = 'number';
	case Arrow = 'arrow';
	case Bullet = 'bullet';
}
