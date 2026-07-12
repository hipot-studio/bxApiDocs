<?php

namespace Bitrix\Call\Agent\Userlog;

use Bitrix\Call\Counter;
use Bitrix\Main\Application;
use Bitrix\Main\Config\Option;
use Bitrix\Main\DB\PgsqlConnection;
use Bitrix\Main\Type\DateTime;

class CallUserLogCleaner
{
	private const PERIOD = '-3 month';

	public static function run(): string
	{
		$expireDate = new DateTime();
		$expireDate->add(self::PERIOD);

		$connection = Application::getConnection();

		$sqlExpireDate = $connection->getSqlHelper()->convertToDbDateTime($expireDate);

		if ($connection instanceof PgsqlConnection)
		{
			$connection->queryExecute("
				WITH ids AS (
					SELECT ID FROM b_call_userlog WHERE STATUS_TIME < {$sqlExpireDate}
				),
				d1 AS (
					DELETE FROM b_call_userlog_index ui USING ids WHERE ui.USERLOG_ID = ids.ID
				),
				d2 AS (
					DELETE FROM b_call_userlog_counters uc USING ids WHERE uc.USERLOG_ID = ids.ID
				)
				DELETE FROM b_call_userlog ul USING ids WHERE ul.ID = ids.ID
			");
		}
		else
		{
			$connection->queryExecute("
				DELETE ul, ui, uc
				FROM b_call_userlog ul
				LEFT JOIN b_call_userlog_index ui ON ui.USERLOG_ID = ul.ID
				LEFT JOIN b_call_userlog_counters uc ON uc.USERLOG_ID = ul.ID
				WHERE ul.STATUS_TIME < {$sqlExpireDate}
			");
		}

		Counter::clearCache();

		return static::getAgentName();
	}

	public static function getAgentName(): string
	{
		return static::class . '::run();';
	}
}
