<?php

namespace Bitrix\Socialnetwork\Log;

use Bitrix\Main\ORM\Query\Query;
use Bitrix\Main\UserAccessTable;
use Bitrix\Socialnetwork\LogRightTable;

/** @internal Shared native log audience policy; inputs have already been normalized by the caller. */
final class ViewerAccess
{
	public static function createLogIdQuery(array $params, array $filter): ?Query
	{
		global $USER;

		if (!isset($params['CHECK_RIGHTS'], $params['USER_ID'])
			|| $params['CHECK_RIGHTS'] != 'Y'
			|| !is_object($USER))
		{
			return null;
		}

		(new \CAccess())->UpdateCodes();
		$userId = (int)$USER->GetID();
		$authorized = $USER->IsAuthorized();
		$codes = UserAccessTable::query()->setSelect(['ACCESS_CODE'])->where('USER_ID', $userId);
		$audience = Query::filter()->logic('or');
		if ($authorized && isset($params['MY_GROUPS_ONLY']) && $params['MY_GROUPS_ONLY'] === 'Y')
		{
			$audience->where(Query::filter()
				->whereExpr("%s LIKE 'SG%%'", ['GROUP_CODE'])
				->whereIn('GROUP_CODE', $codes)
			)->where('GROUP_CODE', 'U' . $userId);
		}
		else
		{
			$audience->where('GROUP_CODE', 'G2');
			if (\CSocNetUser::IsCurrentUserModuleAdmin())
			{
				$audience->where('GROUP_CODE', 'SA');
			}
			if ($authorized)
			{
				$audience->where('GROUP_CODE', 'AU')->whereIn('GROUP_CODE', $codes);
				if (isset($params['CHECK_RIGHTS_OSG']) && $params['CHECK_RIGHTS_OSG'] === 'Y')
				{
					$audience->whereExpr("%s LIKE 'OSG%%'", ['GROUP_CODE']);
				}
			}
			if (!empty($filter['LOG_RIGHTS_SG']) && !is_array($filter['LOG_RIGHTS_SG']))
			{
				$audience->where('GROUP_CODE', $filter['LOG_RIGHTS_SG']);
			}
		}

		return LogRightTable::query()->setSelect(['LOG_ID'])->where($audience);
	}
}
