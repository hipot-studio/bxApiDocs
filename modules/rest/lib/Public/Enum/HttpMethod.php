<?php

declare(strict_types=1);

namespace Bitrix\Rest\Public\Enum;

enum HttpMethod: string
{
	case Get = 'GET';
	case Post = 'POST';
}
