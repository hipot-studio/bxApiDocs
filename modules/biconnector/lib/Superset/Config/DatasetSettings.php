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

	/**
	 * The answer for a portal whose data has just been wiped: the instance it gets next is a new one and has no
	 * legacy datasets, so the original types are passed from the start and the warning about them announces
	 * nothing. Written right away and not left to the deferred initialization, because a wipe can raise the new
	 * instance in the same request - and then that initialization, which asks whether an instance exists, would
	 * answer for the new one and turn the typing off.
	 */
	public static function enableTypingForNewInstance(): void
	{
		Option::set('biconnector', self::TYPING_LOCK_OPTION_NAME, 'Y');
		Option::set('biconnector', self::TYPING_OPTION_NAME, 'Y');
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
