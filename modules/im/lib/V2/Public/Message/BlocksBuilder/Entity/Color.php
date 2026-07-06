<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Public\Message\BlocksBuilder\Entity;

enum Color: string
{
	case Primary = 'primary';
	case Secondary = 'secondary';
	case Alert = 'alert';
	case Base = 'base';
	case Tertiary = 'tertiary';
	case Success = 'success';
	case AiAssistant = 'ai-assistant';
}
