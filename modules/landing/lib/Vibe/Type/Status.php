<?php
declare(strict_types=1);

namespace Bitrix\Landing\Vibe\Type;

enum Status: string
{
	case Registered = 'R';
	case Processed = 'P';
	case Unregistered = 'U';
	case Created = 'C';
}
