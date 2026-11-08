<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Recent\Query;

use Bitrix\Im\V2\Entity\User\User;
use Bitrix\Im\V2\Entity\User\UserGuest;
use Bitrix\Im\V2\Integration\Socialnetwork\Group;
use Bitrix\Main\Loader;
use Bitrix\Main\ORM\Query\Query;

final class CompanionVisibilityFilterFactory
{
	public function forReader(int $readerId): CompanionVisibilityFilter
	{
		return new CompanionVisibilityFilter($this->getVisibleCompanionsQuery($readerId));
	}

	/**
	 * Narrowing is for an external reader only: an employee sees the whole portal and the subquery is
	 * skipped. Same choice of source as the legacy list, see \CIMRecent::getList().
	 */
	private function getVisibleCompanionsQuery(int $readerId): ?Query
	{
		if (!Loader::includeModule('intranet') || \Bitrix\Intranet\Util::isIntranetUser($readerId))
		{
			return null;
		}

		return User::getInstance($readerId) instanceof UserGuest
			? Group::getGuestSharedUsersQuery($readerId)
			: Group::getExtranetAccessibleUsersQuery($readerId)
		;
	}
}
