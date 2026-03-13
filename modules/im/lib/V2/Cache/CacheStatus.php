<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Cache;

enum CacheStatus
{
	case Hit;
	case Miss;
	case NegativeHit;
}
