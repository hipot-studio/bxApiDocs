<?php

namespace Bitrix\ImOpenLines\V2\Statistics;

use Bitrix\ImOpenlines\Security\Helper as SecurityHelper;
use Bitrix\ImOpenlines\Security\Permissions;

/**
 * Shared operator-visibility predicate for imopenlines.v2 statistics REST methods.
 *
 * Resolves the ENTITY_SESSION/ACTION_VIEW permission for a user into the set of
 * operator IDs whose sessions that user is allowed to see, via
 * {@see SecurityHelper::getAllowedUserIds()}. Extracted from the identical private
 * method duplicated across SessionListService, SessionStatService and
 * TransferListService (phase 2) so phase 3 services can reuse the same predicate.
 */
final class AllowedOperators
{
	/**
	 * @return int[]|null Null means no restriction (any operator); [] means no visibility at all.
	 */
	public static function getAllowedOperatorIds(int $userId): ?array
	{
		$permissions = Permissions::createWithUserId($userId);
		$permissionCode = $permissions->getPermission(Permissions::ENTITY_SESSION, Permissions::ACTION_VIEW);

		$allowed = SecurityHelper::getAllowedUserIds($userId, $permissionCode);

		return $allowed === null ? null : array_map('intval', $allowed);
	}
}
