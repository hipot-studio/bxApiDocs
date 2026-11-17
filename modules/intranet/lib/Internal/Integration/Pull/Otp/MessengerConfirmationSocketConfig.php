<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Integration\Pull\Otp;

use Bitrix\Main\Context;
use Bitrix\Main\Web\Uri;

/**
 * Turns a raw pull connection config into the socketConfig shape of the login contract.
 * An empty config degrades the client to polling instead of breaking the scenario.
 */
final class MessengerConfirmationSocketConfig
{
	private const JSON_RPC_SERVER_VERSION = 5;

	public static function build(?array $connectionConfig): array
	{
		$config = $connectionConfig ?? [];
		$server = is_array($config['server'] ?? null) ? $config['server'] : [];
		$isJsonRpc = (int)($server['version'] ?? 0) >= self::JSON_RPC_SERVER_VERSION;
		$isSecure = Context::getCurrent()?->getRequest()?->isHttps() ?? false;
		$path = (string)($isSecure ? ($server['websocket_secure'] ?? '') : ($server['websocket'] ?? ''));

		if ($path === '')
		{
			return ['jsonRpc' => false, 'url' => null];
		}

		$jwt = $config['jwt'] ?? null;
		$params = is_string($jwt) && $jwt !== ''
			? ['token' => $jwt]
			: ['CHANNEL_ID' => implode('/', self::collectChannelIds($config))];

		if (($server['mode'] ?? null) === 'shared' && isset($config['clientId']))
		{
			$params['clientId'] = $config['clientId'];
		}

		$params[$isJsonRpc ? 'jsonRpc' : 'binaryMode'] = 'true';
		$path = (new Uri($path))->addParams($params)->getUri();

		return ['jsonRpc' => $isJsonRpc, 'url' => $path];
	}

	private static function collectChannelIds(array $config): array
	{
		$channels = is_array($config['channels'] ?? null) ? $config['channels'] : [];
		$ids = [];
		foreach ($channels as $channel)
		{
			if (isset($channel['id']))
			{
				$ids[] = $channel['id'];
			}
		}

		return $ids;
	}
}
