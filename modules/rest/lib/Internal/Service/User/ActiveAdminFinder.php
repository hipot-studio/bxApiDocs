<?php

declare(strict_types=1);

namespace Bitrix\Rest\Internal\Service\User;

use Bitrix\Main\Application;
use Bitrix\Main\DB\SqlExpression;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Main\UserGroupTable;

/**
 * Resolves the first active portal administrator.
 *
 * Used as a privileged initiator for forced install/create flows that must bypass
 * the acting user's portal permissions.
 */
final class ActiveAdminFinder
{
	private const ADMIN_GROUP_ID = 1;

	public function findFirstActiveAdminId(): ?int
	{
		$now = new SqlExpression(
			Application::getConnection()->getSqlHelper()->getCurrentDateTimeFunction()
		);

		$row = UserGroupTable::query()
			->setSelect(['USER_ID'])
			->where('GROUP_ID', self::ADMIN_GROUP_ID)
			->where('USER.ACTIVE', 'Y')
			->where(
				Query::filter()
					->logic('or')
					->whereNull('DATE_ACTIVE_FROM')
					->where('DATE_ACTIVE_FROM', '<=', $now)
			)
			->where(
				Query::filter()
					->logic('or')
					->whereNull('DATE_ACTIVE_TO')
					->where('DATE_ACTIVE_TO', '>=', $now)
			)
			->setOrder(['USER_ID' => 'ASC'])
			->setLimit(1)
			->exec()
			->fetch();

		return empty($row['USER_ID']) ? null : (int)$row['USER_ID'];
	}
}
