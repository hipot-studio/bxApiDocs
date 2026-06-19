<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Message\Builder\Entity;

enum Field: string
{
	case Text = 'text';
	case Id = 'id';
	case Size = 'size';
	case Status = 'status';
	case ImageUrl = 'imageUrl';
	case Rows = 'rows';
	case Elements = 'elements';
	case Icon = 'icon';
	case Color = 'color';
	case Blocks = 'blocks';
	case Type = 'type';
}
