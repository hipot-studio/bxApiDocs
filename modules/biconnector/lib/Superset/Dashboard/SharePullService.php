<?php

namespace Bitrix\BIConnector\Superset\Dashboard;

use Bitrix\Main\Loader;

class SharePullService
{
	private const TAG_PREFIX = 'bic_share_';
	public const COMMAND_REVOKE = 'shareRevoked';

	public static function getChannelTag(string $token): string
	{
		return self::TAG_PREFIX . $token;
	}

	public static function getPullConfig(string $token): array
	{
		if (!Loader::includeModule('pull'))
		{
			return [];
		}

		$channel = \Bitrix\Pull\Model\Channel::createWithTag(self::getChannelTag($token));

		return \Bitrix\Pull\Config::get(['CHANNEL' => $channel, 'JSON' => true]) ?: [];
	}

	public static function sendRevokeEvent(string $token): void
	{
		if (!Loader::includeModule('pull'))
		{
			return;
		}

		$channel = \Bitrix\Pull\Model\Channel::createWithTag(self::getChannelTag($token));
		\Bitrix\Pull\Event::add(
			[$channel],
			[
				'module_id' => 'biconnector',
				'command' => self::COMMAND_REVOKE,
				'params' => [],
			]
		);
	}
}
