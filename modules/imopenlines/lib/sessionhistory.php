<?php

namespace Bitrix\ImOpenLines;

use Bitrix\ImOpenLines\Model\SessionTable;
use Bitrix\Main\Config\Option;

/**
 * Class for working with client session history
 */
class SessionHistory
{
	/**
	 * Find previous sessions for a user with different CONFIG_ID
	 *
	 * @param int $userId User ID
	 * @param int $currentConfigId Current configuration ID
	 * @param int $limit Maximum number of sessions to return
	 * @return array Array of session data
	 */
	public static function getPreviousSessionsWithDifferentConfig(
		int $userId,
		int $currentConfigId,
		int $limit = 5
	): array
	{
		if ($userId <= 0 || $currentConfigId <= 0)
		{
			return [];
		}

		$extraUrl = Option::get('imopenlines', 'filter_extra_url', false);

		if (!$extraUrl)
		{
			return [];
		}

		$rowLimit = (int)Option::get('imopenlines', 'previous_sessions_history_limit', $limit);

		$filter = [
			'=USER_ID' => $userId,
			'!=CONFIG_ID' => $currentConfigId,
			'=CLOSED' => 'Y',
			'=SOURCE' => Connector::TYPE_NETWORK,
			[
				'LOGIC' => 'AND',
				['!=EXTRA_URL' => null],
				['!=EXTRA_URL' => $extraUrl],
			],

		];

		$sessions = SessionTable::getList([
			'select' => [
				'ID',
				'DATE_CREATE',
			],
			'filter' => $filter,
			'order' => ['DATE_CREATE' => 'DESC'],
			'limit' => $rowLimit,
		])->fetchAll();

		return $sessions;
	}
}
