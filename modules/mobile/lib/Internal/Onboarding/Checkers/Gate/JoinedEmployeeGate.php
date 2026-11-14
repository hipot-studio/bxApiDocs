<?php

namespace Bitrix\Mobile\Internal\Onboarding\Checkers\Gate;

use Bitrix\Main\Loader;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Mobile\Internal\Onboarding\Checkers\PortalEmployeeQuery;

/**
 * Base of the gates that look for a colleague on the portal. Holds the one definition
 * of "a colleague who has joined" shared by them, so the conditions of the gates differ
 * only in what they add to it.
 */
abstract class JoinedEmployeeGate implements StepGate
{
	public function isOpen(int $userId, GateContext $context): bool
	{
		if ($userId <= 0 || !Loader::includeModule('intranet'))
		{
			return false;
		}

		return $this->hasJoinedColleague($userId, $context);
	}

	abstract protected function hasJoinedColleague(int $userId, GateContext $context): bool;

	protected function exists(Query $query): bool
	{
		return !empty($query->fetch());
	}

	/**
	 * A colleague of the portal who finished registration. CONFIRM_CODE is cleared on
	 * registration, so an empty one is what separates "joined" from "merely invited";
	 * the rest of the definition is shared with the checks of the steps.
	 */
	protected function createJoinedEmployeeQuery(int $excludeUserId): Query
	{
		return PortalEmployeeQuery::create()
			->whereNot('ID', $excludeUserId)
			->addFilter('CONFIRM_CODE', false)
			->setLimit(1)
		;
	}
}
