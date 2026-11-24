<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Marketplace\Banner\Managed;

enum RemoteStatus: string
{
	case Campaign = 'campaign';
	case Empty = 'empty';
	case Error = 'error';
}
