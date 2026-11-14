<?php

namespace Bitrix\StaffTrack\Internal\Integration\Intranet;

use Bitrix\Intranet\Util;
use Bitrix\Main\Loader;

/**
 * Whether a person belongs to the company, asked of the module that knows it. A portal without that
 * module tells one kind of person from another in no way at all, so there everybody passes.
 */
class UserService
{
	public static function isIntranetUser(int $userId): bool
	{
		if ($userId <= 0)
		{
			return false;
		}

		if (Loader::includeModule('intranet') && !Util::isIntranetUser($userId))
		{
			return false;
		}

		return true;
	}
}
