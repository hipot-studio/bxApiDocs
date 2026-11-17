<?php

declare(strict_types=1);

namespace Bitrix\Main\Engine\ActionFilter\CacheResponse;

enum ContextField: string
{
	case User = 'user';
	case Ip = 'ip';
	case Site = 'site';
	case Language = 'language';
	case Host = 'host';
}
