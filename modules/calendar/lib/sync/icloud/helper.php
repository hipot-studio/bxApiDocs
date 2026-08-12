<?php

namespace Bitrix\Calendar\Sync\Icloud;

class Helper
{
	public const SERVER_PATH = 'https://caldav.icloud.com/';
	public const ACCOUNT_TYPE = 'icloud';
	public const CONNECTION_NAME = 'ICloud (#NAME#)';

	/**
	 * Cross-process advisory lock key prefix. It serializes the initial import
	 * of a single iCloud connection so every import caller site contends on the same key.
	 */
	public const IMPORT_LOCK_NAME_PREFIX = 'calendar:icloud:sync:';

	public const EXCLUDED_CALENDARS = [
		'inbox',
		'outbox',
		'notification',
		'tasks',
		'calendars',
	];

	public static function getImportLockName(int $connectionId): string
	{
		return self::IMPORT_LOCK_NAME_PREFIX . $connectionId;
	}

	/**
	 * @param string $accountType
	 * @return bool
	 */
	public function isVendorConnection(string $accountType): bool
	{
		return $accountType === self::ACCOUNT_TYPE;
	}

	public function getConnectionName(string $appleId): string
	{
		return str_replace(
			'#NAME#',
			$appleId,
			self::CONNECTION_NAME,
		);
	}
}
