<?php

namespace Bitrix\Mobile\Livefeed;

use Bitrix\Main\Loader;

final class LegacyCommentAccess
{
	public static function getVisibleLog(int $logId, ?string $currentUserExternalAuthId): ?array
	{
		if ($logId <= 0)
		{
			return null;
		}

		$rawLog = \CSocNetLog::GetByID($logId);
		if (!is_array($rawLog))
		{
			return null;
		}

		if (
			str_starts_with((string)$rawLog['ENTITY_TYPE'], 'CRM')
			&& $currentUserExternalAuthId !== 'email'
		)
		{
			if (!Loader::includeModule('crm'))
			{
				return null;
			}

			return self::fetchLog($logId, [
				'IS_CRM' => 'Y',
				'CHECK_CRM_RIGHTS' => 'Y',
			]);
		}

		return self::fetchLog($logId, [
			'CHECK_RIGHTS' => 'Y',
			'USE_SUBSCRIBE' => 'N',
		]);
	}

	private static function fetchLog(int $logId, array $listParams): ?array
	{
		$result = \CSocNetLog::GetList(
			[],
			['ID' => $logId],
			false,
			false,
			[],
			$listParams,
		);
		if (!is_object($result))
		{
			return null;
		}
		$log = $result->Fetch();

		return is_array($log) ? $log : null;
	}
}
