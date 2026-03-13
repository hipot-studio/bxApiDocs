<?php

namespace Bitrix\ImBot;

class Link extends \IRestService
{
	private const ALLOWED_BOTS = [
		'careteam',
		'copilotchatbot',
		'csmbot',
		'giphy',
		'hrbot',
		'marta',
		'onboardingbot',
		'partner24',
		'properties',
		'propertiesua',
		'salesupport24',
		'support24',
		'supportbox',
		'supportservice',
	];

	private static function getBotId(string $botCode): int
	{
		if (!in_array($botCode, self::ALLOWED_BOTS, true))
		{
			return 0;
		}

		$className = '\\Bitrix\\ImBot\\Bot\\' . $botCode;
		$bot = new $className();
		if (!($bot instanceof \Bitrix\ImBot\Bot\Base))
		{
			return 0;
		}

		return $bot->getBotId();
	}

	public static function getChatUrlWithBot(string $botCode): ?string
	{
		$botId = self::getBotId($botCode);
		if (!$botId)
		{
			return null;
		}

		return '/online/?IM_DIALOG=' . $botId;
	}
}
