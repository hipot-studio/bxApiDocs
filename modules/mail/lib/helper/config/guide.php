<?php

declare(strict_types=1);

namespace Bitrix\Mail\Helper\Config;

use Bitrix\Main\Application;

class Guide
{
	public const USER_OPTION_CATEGORY = 'mail.guide';
	public const USER_OPTION_MAILBOX_GRID_NAME = 'mailbox_grid_guide_shown';
	public const USER_OPTION_MAILBOX_GRID_NOT_CIS_NAME = 'mailbox_grid_guide_shown_not_cis';
	public const USER_OPTION_MAILBOX_LIST_HINT_NAME = 'mailbox_list_hint_shown';
	public const USER_OPTION_MAILBOX_LIST_HINT_NOT_CIS_NAME = 'mailbox_list_hint_shown_not_cis';

	public static function wasMailboxGridGuideShown(): bool
	{
		$userOption = self::getMailboxGridGuideOptionName();

		return \CUserOptions::GetOption(self::USER_OPTION_CATEGORY, $userOption, null) === 'Y';
	}

	public static function getMailboxGridGuideOptionName(): string
	{
		return self::isCisLicense() ? self::USER_OPTION_MAILBOX_GRID_NAME : self::USER_OPTION_MAILBOX_GRID_NOT_CIS_NAME;
	}

	public static function wasMailboxListShown(): bool
	{
		$userOption = self::getMailboxListHintOptionName();

		return \CUserOptions::GetOption(self::USER_OPTION_CATEGORY, $userOption, null) === 'Y';
	}

	public static function getMailboxListHintOptionName(): string
	{
		return self::isCisLicense() ? self::USER_OPTION_MAILBOX_LIST_HINT_NAME : self::USER_OPTION_MAILBOX_LIST_HINT_NOT_CIS_NAME;
	}

	private static function isCisLicense(): bool
	{
		return Application::getInstance()->getLicense()->isCis();
	}
}
