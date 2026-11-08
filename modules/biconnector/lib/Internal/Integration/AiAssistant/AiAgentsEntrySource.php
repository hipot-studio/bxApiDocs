<?php

namespace Bitrix\BIConnector\Internal\Integration\AiAssistant;

final class AiAgentsEntrySource
{
	public const SOURCE_MENU = 'menu';
	public const SOURCE_PROMO = 'promo';
	public const SOURCE_DIRECT = 'direct';

	public static function normalize(mixed $source): string
	{
		return in_array($source, [self::SOURCE_MENU, self::SOURCE_PROMO], true) ? $source : self::SOURCE_DIRECT;
	}
}
