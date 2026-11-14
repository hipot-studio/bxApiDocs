<?php

namespace Bitrix\Mobile\Internal\Onboarding\Checkers;

use Bitrix\Intranet\ActionFilter\UserType;
use Bitrix\Intranet\UserTable;
use Bitrix\Main\ORM\Query\Query;

/**
 * The single definition of "a colleague of the portal" the series runs on: an active real
 * employee. USER_TYPE keeps extranet guests, collab participants and integrator accounts
 * out, REAL_USER keeps bot, imconnector, email and sale accounts out - none of them is
 * somebody the user could have invited or written to.
 *
 * The gates of the steps and the checks of the steps both ask this question, and an answer
 * that differs between them is an answer nobody can read: a step credited by a companion
 * its own gate does not count as a colleague.
 */
final class PortalEmployeeQuery
{
	public static function create(): Query
	{
		return UserTable::query()
			->setSelect(['ID'])
			->where('ACTIVE', 'Y')
			->where('REAL_USER', 'expr', true)
			->where('USER_TYPE', UserType::TYPE_EMPLOYEE)
		;
	}
}
