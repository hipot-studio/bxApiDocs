<?php

namespace Bitrix\Mobile;

use Bitrix\Main\Loader;
use Bitrix\Mobile\Public\Provider\DeeplinkProvider;

/**
 * Kept for the modules and the event registration that already address this class.
 *
 * @deprecated use \Bitrix\Mobile\Public\Provider\DeeplinkProvider
 */
class Deeplink
{
	public static function getAuthLink($intent, int $userId = null, int $ttl = null): string
	{
		return (new DeeplinkProvider())->getAuthLink((string)$intent, $userId, $ttl);
	}

	public static function onOneTimeHashRemoved($userId, $hash): void
	{
		if (Loader::includeModule('pull'))
		{
			\CPullStack::AddByUser($userId,
				[
					'module_id' => 'mobile',
					'command' => 'onDeeplinkShouldRefresh',
					'params' => ['previous_hash' => $hash],
				]
			);
		}
	}
}
