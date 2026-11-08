<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Public\Type\User;

enum InitializeUserMode: string
{
	case Standard = 'Standard';
	case FirstAuthorizeFallback = 'FirstAuthorizeFallback';
}
