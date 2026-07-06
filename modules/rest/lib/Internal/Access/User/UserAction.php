<?php

declare(strict_types=1);

namespace Bitrix\Rest\Internal\Access\User;

enum UserAction: string
{
	case Authorize = 'authorize';
}
