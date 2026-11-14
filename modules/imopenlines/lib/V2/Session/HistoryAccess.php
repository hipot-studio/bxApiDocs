<?php

namespace Bitrix\ImOpenLines\V2\Session;

use Bitrix\ImOpenLines\Crm\Common;
use Bitrix\ImOpenLines\Security\Helper;
use Bitrix\ImOpenLines\Security\Permissions;

/**
 * Single source of truth for "may this user view the open line session history".
 *
 * Shared by the legacy history window (\Bitrix\ImOpenLines\Operator::getSessionHistory())
 * and the V2 read-only endpoint, so the two access rules cannot diverge. The permission map
 * and the CRM activity branch are resolved for the passed $userId, not for the current user.
 */
final class HistoryAccess
{
	/**
	 * @param array|Session $session Session row (legacy performance fetch) or V2 session object.
	 * @param int $userId User whose access is being checked.
	 */
	public static function canView(array|Session $session, int $userId): bool
	{
		// An unassigned session has OPERATOR_ID = 0, so the owner branch must not match user id 0.
		if ($userId <= 0)
		{
			return false;
		}

		$operatorId = self::getOperatorId($session);

		if ($operatorId === $userId)
		{
			return true;
		}

		// Legacy quirk preserved: a session row keyed by the user id grants access.
		if (is_array($session) && isset($session[$userId]))
		{
			return true;
		}

		$permission = Permissions::createWithUserId($userId);
		$allowedUserIds = Helper::getAllowedUserIds(
			$userId,
			$permission->getPermission(Permissions::ENTITY_HISTORY, Permissions::ACTION_VIEW)
		);

		// A null list means the ENTITY_HISTORY permission is ANY: every operator's session is allowed.
		// The lookup stays non-strict - the department branch of the list holds string ids.
		if (!is_array($allowedUserIds) || in_array($operatorId, $allowedUserIds))
		{
			return true;
		}

		$crmAccess = Common::hasAccessToEntitiesBindingActivity(self::getCrmActivityId($session), $userId);

		return $crmAccess->getResult() === true;
	}

	private static function getOperatorId(array|Session $session): int
	{
		if ($session instanceof Session)
		{
			return (int)$session->getOperatorId();
		}

		return (int)($session['OPERATOR_ID'] ?? 0);
	}

	private static function getCrmActivityId(array|Session $session): ?int
	{
		if ($session instanceof Session)
		{
			return $session->getCrmActivityId();
		}

		return isset($session['CRM_ACTIVITY_ID']) ? (int)$session['CRM_ACTIVITY_ID'] : null;
	}
}
