<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Message\BlocksBuilder\Entity\Blocks\Field\Buttons;

enum Type: string
{
	case LinkButton = 'linkButton';
	case EventButton = 'eventButton';
}
