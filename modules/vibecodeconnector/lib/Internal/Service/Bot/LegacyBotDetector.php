<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Service\Bot;

use Bitrix\Im\Model\BotTable;
use Bitrix\Main\Loader;

final class LegacyBotDetector
{
	public const LEGACY_CODES = ['vibecode_platform', 'vibecode_platform_dev'];

	/**
	 * @return list<array{botId:int, appId:string, code:string}>
	 */
	public function find(): array
	{
		if (!Loader::includeModule('im'))
		{
			return [];
		}

		$rows = BotTable::getList([
			'filter' => [
				'=CODE' => self::LEGACY_CODES,
				'=MODULE_ID' => 'rest',
			],
			'select' => ['BOT_ID', 'APP_ID', 'CODE'],
		]);

		$found = [];
		while ($row = $rows->fetch())
		{
			$found[] = [
				'botId' => (int)$row['BOT_ID'],
				'appId' => (string)$row['APP_ID'],
				'code' => (string)$row['CODE'],
			];
		}

		return $found;
	}
}
