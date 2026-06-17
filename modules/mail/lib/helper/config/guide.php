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
	public const USER_OPTION_MAILBOX_LIST_GEAR_HIGHLIGHT_NAME = 'mailbox_list_gear_highlight_shown';
	public const USER_OPTION_DISCUSS_IN_CHAT_GUIDE_NAME = 'discuss_in_chat_guide_shown';
	public const USER_OPTION_CONNECTION_REQUEST_NAME = 'connection_request_guide_shown';
	public const USER_OPTION_FOLDER_SORT_GUIDE_NAME = 'folder_sort_guide_shown';
	public const USER_OPTION_ALL_MAIL_MODE_GUIDE_NAME = 'all_mail_mode_guide_shown';

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

	public static function wasDiscussInChatGuideShown(): bool
	{
		$userOption = self::getDiscussInChatGuideOptionName();

		return \CUserOptions::GetOption(self::USER_OPTION_CATEGORY, $userOption, null) === 'Y';
	}

	public static function getDiscussInChatGuideOptionName(): string
	{
		return self::USER_OPTION_DISCUSS_IN_CHAT_GUIDE_NAME;
	}

	public static function wasMailboxListGearHighlightShown(): bool
	{
		return \CUserOptions::GetOption(
			self::USER_OPTION_CATEGORY,
			self::USER_OPTION_MAILBOX_LIST_GEAR_HIGHLIGHT_NAME,
			null,
		) === 'Y';
	}

	public static function getMailboxListGearHighlightOptionName(): string
	{
		return self::USER_OPTION_MAILBOX_LIST_GEAR_HIGHLIGHT_NAME;
	}

	public static function wasConnectionRequestGuideShown(): bool
	{
		return \CUserOptions::GetOption(
			self::USER_OPTION_CATEGORY,
			self::USER_OPTION_CONNECTION_REQUEST_NAME,
			null,
		) === 'Y';
	}

	public static function getConnectionRequestGuideOptionName(): string
	{
		return self::USER_OPTION_CONNECTION_REQUEST_NAME;
	}

	public static function wasFolderSortGuideShown(): bool
	{
		$userOption = self::getFolderSortGuideOptionName();

		return \CUserOptions::GetOption(self::USER_OPTION_CATEGORY, $userOption, null) === 'Y';
	}

	public static function getFolderSortGuideOptionName(): string
	{
		return self::USER_OPTION_FOLDER_SORT_GUIDE_NAME;
	}

	public static function wasAllMailModeGuideShown(): bool
	{
		$userOption = self::getAllMailModeGuideOptionName();

		return \CUserOptions::GetOption(self::USER_OPTION_CATEGORY, $userOption, null) === 'Y';
	}

	public static function getAllMailModeGuideOptionName(): string
	{
		return self::USER_OPTION_ALL_MAIL_MODE_GUIDE_NAME;
	}

	private static function isCisLicense(): bool
	{
		return Application::getInstance()->getLicense()->isCis();
	}
}
