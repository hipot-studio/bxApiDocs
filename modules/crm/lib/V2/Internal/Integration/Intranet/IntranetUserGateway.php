<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Integration\Intranet;

use Bitrix\Intranet\Util;
use Bitrix\Main\Loader;

/**
 * Whether a user is an intranet one - an employee of the company rather than an extranet user or a collaber -
 * decided as the prefilter IntranetUser of the intranet module decides it. Without the intranet module there
 * are no other kinds of users, so every user is.
 *
 * Not final: tests substitute the kind of a user.
 *
 * @internal
 */
class IntranetUserGateway
{
	public function isIntranetUser(int $userId): bool
	{
		if (!Loader::includeModule('intranet'))
		{
			return true;
		}

		return Util::isIntranetUser($userId);
	}
}
