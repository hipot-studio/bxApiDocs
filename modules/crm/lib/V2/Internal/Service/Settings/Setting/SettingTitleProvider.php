<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Settings\Setting;

use Bitrix\Main\Localization\Loc;
use Bitrix\Main\SystemException;

Loc::loadMessages(__FILE__);

/** @internal */
final class SettingTitleProvider
{
	private const MESSAGE_PREFIX = 'CRM_V2_SETTING_';
	private const HINT_SUFFIX = '_HINT';

	/** @throws SystemException */
	public static function getTitle(string $code): string
	{
		$message = Loc::getMessage(self::getMessageCode($code));
		if (!is_string($message) || $message === '')
		{
			throw new SystemException("Localization for setting '{$code}' is missing");
		}

		return $message;
	}

	/** @throws SystemException */
	public static function getOptionTitle(string $code, string $optionCode): string
	{
		$message = Loc::getMessage(self::getMessageCode($code . '.option.' . $optionCode));
		if (!is_string($message) || $message === '')
		{
			throw new SystemException(
				"Localization for setting option '{$code}.{$optionCode}' is missing",
			);
		}

		return $message;
	}

	public static function getHint(string $code): ?string
	{
		$message = Loc::getMessage(self::getMessageCode($code) . self::HINT_SUFFIX);

		return is_string($message) && $message !== '' ? $message : null;
	}

	private static function getMessageCode(string $code): string
	{
		return self::MESSAGE_PREFIX . mb_strtoupper(str_replace('.', '_', $code));
	}
}
