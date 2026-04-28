<?php

namespace Bitrix\BIConnector\Superset\Config;

use Bitrix\BIConnector\Integration\Superset\SupersetInitializer;
use Bitrix\Main\Config\Option;

final class DatasetSettings
{
	public const TYPING_OPTION_NAME = 'dataset_typing_enabled';
	public const TYPING_LOCK_OPTION_NAME = 'dataset_typing_locked';

	public static function setTypingOption(?string $value): string
	{
		if (self::isTypingLocked())
		{
			$value = 'Y';
		}
		elseif ($value !== 'Y' && $value !== 'N')
		{
			$value = self::isTypingEnabled() ? 'Y' : 'N';
		}

		Option::set('biconnector', self::TYPING_OPTION_NAME, $value);

		return $value;
	}

	public static function isTypingEnabled(): bool
	{
		$value = Option::get('biconnector', self::TYPING_OPTION_NAME, null);

		if ($value === null)
		{
			$value = self::initTypingOptionForNewPortal();
		}

		return $value === 'Y';
	}

	public static function isTypingLocked(): bool
	{
		return Option::get('biconnector', self::TYPING_LOCK_OPTION_NAME, 'N') === 'Y';
	}

	private static function initTypingOptionForNewPortal(): string
	{
		$isSupersetExist = SupersetInitializer::isSupersetExist();

		if (!$isSupersetExist)
		{
			Option::set('biconnector', self::TYPING_LOCK_OPTION_NAME, 'Y');
		}

		$value = !$isSupersetExist ? 'Y' : 'N';
		Option::set('biconnector', self::TYPING_OPTION_NAME, $value);

		return $value;
	}
}
