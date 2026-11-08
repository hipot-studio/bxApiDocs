<?php

declare(strict_types=1);

namespace Bitrix\Mail\Helper\Config;

use Bitrix\Mail\Helper\Label\LabelsFeature;
use Bitrix\Mail\MailboxTable;
use Bitrix\Main\Application;
use Bitrix\Main\Config\Option;

class Guide
{
	public const UNDO_SEND_ONBOARDING_BOUNDARY_ID_OPTION = 'undo_send_onboarding_boundary_id';

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
	public const USER_OPTION_LABELS_ONBOARDING_NAME = 'labels_onboarding';
	public const USER_OPTION_LARGE_ATTACHMENT_AHA_NAME = 'large_attachment_aha_shown';
	public const USER_OPTION_LARGE_ATTACHMENT_POST_SEND_NAME = 'large_attachment_post_send_prompt_suppressed';
	public const USER_OPTION_UNDO_SEND_NAME = 'undo_send_guide_shown';
	public const USER_OPTION_UNDO_SEND_READY_NAME = 'undo_send_guide_ready';
	public const USER_OPTION_UNDO_SEND_AHA_NAME = self::USER_OPTION_UNDO_SEND_NAME;
	public const UNDO_SEND_BOUNDARY_ID_OPTION = self::UNDO_SEND_ONBOARDING_BOUNDARY_ID_OPTION;

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

	public static function wasLabelsOnboardingShown(): bool
	{
		return \CUserOptions::GetOption(
			self::USER_OPTION_CATEGORY,
			self::USER_OPTION_LABELS_ONBOARDING_NAME,
			null,
		) === 'Y';
	}

	public static function getLabelsOnboardingOptionName(): string
	{
		return self::USER_OPTION_LABELS_ONBOARDING_NAME;
	}

	/**
	 * Shown for an active mailbox with ID within the release boundary. Boundary 0 shows to nobody.
	 */
	public static function shouldShowLabelsOnboarding(int $userId): bool
	{
		if ($userId <= 0 || !LabelsFeature::isEnabled() || self::wasLabelsOnboardingShown())
		{
			return false;
		}

		return self::hasMailboxWithinOnboardingBoundary($userId);
	}

	private static function hasMailboxWithinOnboardingBoundary(int $userId): bool
	{
		$boundaryId = self::getLabelsOnboardingBoundaryId();
		if ($boundaryId <= 0)
		{
			return false;
		}

		return MailboxTable::getRow([
			'select' => ['ID'],
			'filter' => [
				'=USER_ID' => $userId,
				'=ACTIVE' => 'Y',
				'<=ID' => $boundaryId,
			],
		]) !== null;
	}

	private static function getLabelsOnboardingBoundaryId(): int
	{
		return (int)Option::get('mail', LabelsFeature::ONBOARDING_BOUNDARY_ID_OPTION, '0');
	}

	public static function wasLargeAttachmentAhaShown(): bool
	{
		return \CUserOptions::GetOption(
			self::USER_OPTION_CATEGORY,
			self::USER_OPTION_LARGE_ATTACHMENT_AHA_NAME,
			null,
		) === 'Y';
	}

	public static function getLargeAttachmentAhaGuideOptionName(): string
	{
		return self::USER_OPTION_LARGE_ATTACHMENT_AHA_NAME;
	}

	public static function wasLargeAttachmentPostSendPromptSuppressed(): bool
	{
		return \CUserOptions::GetOption(
			self::USER_OPTION_CATEGORY,
			self::USER_OPTION_LARGE_ATTACHMENT_POST_SEND_NAME,
			null,
		) === 'Y';
	}

	public static function getLargeAttachmentPostSendPromptOptionName(): string
	{
		return self::USER_OPTION_LARGE_ATTACHMENT_POST_SEND_NAME;
	}

	public static function shouldShowUndoSendGuide(int $userId): bool
	{
		if ($userId <= 0 || !Feature::isUndoSendAvailable() || self::wasUndoSendGuideShown($userId))
		{
			return false;
		}

		$previousMailboxId = (int)\CUserOptions::GetOption(
			'mail',
			'previous_seen_mailbox_id',
			0,
			$userId,
		);
		$boundaryId = (int)Option::get('mail', self::UNDO_SEND_BOUNDARY_ID_OPTION, '0');
		if ($previousMailboxId <= 0 || $boundaryId <= 0 || $previousMailboxId > $boundaryId)
		{
			return false;
		}

		return MailboxTable::getRow([
			'select' => ['ID'],
			'filter' => [
				'=ID' => $previousMailboxId,
				'=USER_ID' => $userId,
				'=ACTIVE' => 'Y',
			],
		]) !== null;
	}

	public static function initializeUndoSendOnboardingBoundary(int $boundaryId): void
	{
		$missingOption = "\x00absent\x00";
		if (Option::get('mail', self::UNDO_SEND_ONBOARDING_BOUNDARY_ID_OPTION, $missingOption) !== $missingOption)
		{
			return;
		}

		Option::set('mail', self::UNDO_SEND_ONBOARDING_BOUNDARY_ID_OPTION, (string)max(0, $boundaryId));
	}

	public static function wasUndoSendGuideShown(int $userId): bool
	{
		return \CUserOptions::GetOption(
			self::USER_OPTION_CATEGORY,
			self::USER_OPTION_UNDO_SEND_NAME,
			null,
			$userId,
		) === 'Y';
	}

	public static function getUndoSendGuideOptionName(): string
	{
		return self::USER_OPTION_UNDO_SEND_NAME;
	}

	public static function isUndoSendGuideReady(int $userId): bool
	{
		return \CUserOptions::GetOption(
			self::USER_OPTION_CATEGORY,
			self::USER_OPTION_UNDO_SEND_READY_NAME,
			null,
			$userId,
		) === 'Y';
	}

	public static function shouldShowUndoSendAha(int $userId): bool
	{
		return self::shouldShowUndoSendGuide($userId);
	}

	public static function getUndoSendAhaGuideOptionName(): string
	{
		return self::getUndoSendGuideOptionName();
	}

	private static function isCisLicense(): bool
	{
		return Application::getInstance()->getLicense()->isCis();
	}
}
