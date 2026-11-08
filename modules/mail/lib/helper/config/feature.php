<?php

declare(strict_types=1);

namespace Bitrix\Mail\Helper\Config;

use Bitrix\Mail\Helper\MailAccess;
use Bitrix\Mail\Public\Features\InternalDraftsFeature;
use Bitrix\Mail\Public\Features\MailboxAvatarFeature;
use Bitrix\Mail\Public\Features\MessageConversationGridFeature;
use Bitrix\Mail\Public\Features\SenderAvatarFeature;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Loader;
use Bitrix\Main\ModuleManager;
use Bitrix\UI\Util;

class Feature
{
	public const UNLIMITED_MAIL_SYNC_PERIOD_OPTION = 'unlimited_mail_sync_period_enabled';
	public const MIGRATION_NOTICE_OPTION = 'migration_notice_enabled';
	public const MIGRATION_ANNOUNCEMENT_OPTION = 'migration_announcement_enabled';
	public const MIGRATION_CONTROL_PANEL_URL_OPTION = 'migration_control_panel_url';
	public const MIGRATION_HELPDESK_ARTICLE_OPTION = 'migration_helpdesk_article';

	/**
	 * Until the control panel of the company mail exists (Q-1) the button leads to a stub. Substituting
	 * the real address stays an edit of the option and of nothing else: no client of the decision spells
	 * an address of its own.
	 *
	 * It is public because it is also what an address that may not be followed falls back to: the option
	 * is set by hand and promises nothing about its value.
	 */
	public const MIGRATION_CONTROL_PANEL_URL_STUB = '/mail/';

	/**
	 * The helpdesk article behind the "Learn more" link (Q-2, answered). The number alone is kept: the
	 * helpdesk of the product is published per language, and one portal is read by people whose
	 * interface languages differ, so a whole address stored here would freeze the article in one of them.
	 */
	public const MIGRATION_HELPDESK_ARTICLE = '29253108';

	private const AUTO_CLASSIFY_INCOMING_OPTION = 'auto_classify_incoming';
	private const MOBILE_SIGNATURES_OPTION = 'signatures_enabled';

	public static function isInternalDraftsAvailable(): bool
	{
		return \Bitrix\Main\Config\Feature::isEnabled(InternalDraftsFeature::class);
	}

	public static function isInternalDraftsWebAvailable(): bool
	{
		return self::isInternalDraftsAvailable()
			&& InternalDraftCompatibility::isMainVersionSupported(ModuleManager::getVersion('main'))
		;
	}

	public static function isMailboxGridAvailable(): bool
	{
		return true;
	}

	/**
	 * The emergency brake of the grid bulk actions: the panel above the grid, the flag of the mailbox
	 * list screen and the mass actions of the controller. Connecting a mailbox is not behind it.
	 */
	public static function isMailboxGridBulkActionsAvailable(): bool
	{
		return Option::get('mail', 'mailbox_grid_bulk_actions_enabled', 'Y') === 'Y';
	}

	public static function isPasswordlessConnectAvailable(): bool
	{
		return true;
	}

	public static function isMailboxConnectionRequestAvailable(): bool
	{
		return Loader::includeModule('im');
	}

	public static function isMailboxConfigRedesignAvailable(): bool
	{
		return true;
	}

	public static function isMailboxOwnerChangeAvailable(): bool
	{
		return true;
	}

	public static function isMailboxAvatarAvailable(): bool
	{
		return \Bitrix\Main\Config\Feature::isEnabled(MailboxAvatarFeature::class);
	}

	/**
	 * The single photo an address gets across the portal: the permission of the section, the editor
	 * of the letter and the whole sender API. The permission itself is installed regardless of it.
	 */
	public static function isSenderAvatarAvailable(): bool
	{
		return \Bitrix\Main\Config\Feature::isEnabled(SenderAvatarFeature::class);
	}

	public static function isMailListImprovementsAvailable(): bool
	{
		return Option::get('mail', 'enable_list_improvements', 'N') === 'Y';
	}

	public static function isMessageGridVueAvailable(): bool
	{
		return Option::get('mail', 'message_grid_vue_enabled', 'N') === 'Y';
	}

	/** Outranks the user setting: with the flag off nothing is grouped, and the user choice is kept. */
	public static function isMessageConversationGridAvailable(): bool
	{
		return \Bitrix\Main\Config\Feature::isEnabled(MessageConversationGridFeature::class);
	}

	public static function isCrmAvailable(): bool
	{
		return MailAccess::hasCurrentUserAdminAccess()
			|| Option::get('intranet', 'allow_external_mail_crm', 'Y', SITE_ID) === 'Y';
	}

	public static function isUnlimitedMailSyncPeriodAvailable(): bool
	{
		return !ModuleManager::isModuleInstalled('bitrix24')
			|| self::isUnlimitedMailSyncPeriodEnabled()
		;
	}

	public static function isUnlimitedMailSyncPeriodEnabled(): bool
	{
		if (!ModuleManager::isModuleInstalled('bitrix24'))
		{
			return false;
		}

		return Option::get('mail', self::UNLIMITED_MAIL_SYNC_PERIOD_OPTION, 'N', '') === 'Y';
	}

	public static function isAutoClassifyIncomingAvailable(): bool
	{
		return Option::get('mail', self::AUTO_CLASSIFY_INCOMING_OPTION, 'N') === 'Y';
	}

	public static function isFolderManualSortingAvailable(): bool
	{
		return Option::get('mail', 'folder_sorting_enabled', 'N') === 'Y';
	}

	public static function isMessageAddressFilterAvailable(): bool
	{
		return Option::get('mail', 'message_address_filter_enabled', 'N') === 'Y';
	}

	public static function isLargeAttachmentDiskUploadAvailable(): bool
	{
		return Option::get('mail', 'large_attachment_disk_upload_enabled', 'N') === 'Y';
	}

	public static function isComposeRedesignAvailable(): bool
	{
		return Option::get('mail', 'compose_redesign_enabled', 'N') === 'Y';
	}

	public static function isReadRedesignAvailable(): bool
	{
		return Option::get('mail', 'read_redesign_enabled', 'N') === 'Y';
	}

	public static function isComposeUnfinishedElementsAvailable(): bool
	{
		return self::isComposeRedesignAvailable()
			&& Option::get('mail', 'compose_unfinished_elements_enabled', 'N') === 'Y';
	}

	/**
	 * Cancelling a scheduled message turns it into a draft, so scheduling without drafts would offer
	 * the user a letter that cannot be called back. Scheduling a letter and editing a scheduled one are
	 * only possible in the redesigned compose form, so without it the feature would have no entry point.
	 */
	public static function isScheduledMessageAvailable(): bool
	{
		return self::isComposeRedesignAvailable()
			&& self::isInternalDraftsWebAvailable()
			&& Option::get('mail', 'scheduled_message_enabled', 'N') === 'Y';
	}

	public static function isUndoSendAvailable(): bool
	{
		return self::isScheduledMessageAvailable()
			&& Option::get('mail', 'undo_send_enabled', 'N') === 'Y'
		;
	}

	/**
	 * Width of every side panel that shows a single message: compose, draft, scheduled view and edit.
	 * They must stay equal, so the value has a single source.
	 */
	public static function getMessageSliderWidth(): int
	{
		return self::isComposeRedesignAvailable() ? 820 : 960;
	}

	public static function isSignatureImagesAvailable(): bool
	{
		return Option::get('mail', 'signature_images_enabled', 'N') === 'Y';
	}

	public static function isSignatureMacrosAvailable(): bool
	{
		return Option::get('mail', 'signature_macros_enabled', 'N') === 'Y';
	}

	public static function isSharedSignaturePermissionAvailable(): bool
	{
		return Option::get('mail', 'shared_signature_permission_enabled', 'N') === 'Y';
	}

	public static function isMessageDeletePermissionAvailable(): bool
	{
		return Option::get('mail', 'message_delete_permission_enabled', 'N') === 'Y';
	}

	public static function isComposeTemplatesAvailable(): bool
	{
		return Option::get('mail', 'compose_templates_enabled', 'Y') === 'Y';
	}

	/**
	 * Owned by the mailmobile feature flag; read as a plain option so that mail keeps no
	 * dependency on the mobile modules.
	 */
	public static function isMobileSignaturesAvailable(): bool
	{
		return Option::get('mailmobile', self::MOBILE_SIGNATURES_OPTION, 'N') === 'Y';
	}

	public static function isVacationAutoReplyAvailable(): bool
	{
		return Option::get('mail', 'vacation_auto_reply_enabled', 'N') === 'Y';
	}

	/**
	 * Messages about the migration of mail to Bitrix24 Mail. The zone of the portal is not asked: the
	 * mail of Bitrix24 is offered everywhere, so the switch of the module is the whole condition.
	 */
	public static function isMigrationNoticeAvailable(): bool
	{
		return Option::get('mail', self::MIGRATION_NOTICE_OPTION, 'N') === 'Y';
	}

	/**
	 * The announcement of Bitrix24 Mail, behind a switch of its own and under the switch of the feature.
	 *
	 * The announcement invites a person to make the mail of their company, and that is made in the
	 * control panel, which does not exist yet (Q-1). The migration messages can live without it - they
	 * warn about the providers and that warning is due whether the way out is ready or not - but an
	 * invitation to a door that is not there is worse than silence, and it would be spent for good on
	 * the person who saw it.
	 *
	 * So the announcement waits for the panel on a switch of its own, and is turned on separately.
	 */
	public static function isMigrationAnnouncementAvailable(): bool
	{
		return self::isMigrationNoticeAvailable()
			&& Option::get('mail', self::MIGRATION_ANNOUNCEMENT_OPTION, 'N') === 'Y'
		;
	}

	/**
	 * Where the "Move mail" button of a migration message and the panel button of the mailbox list
	 * lead (Q-1). The address is checked here, where the option is read, and in no reader of it: the
	 * decision of the rules, the toolbar button and the hint beside it are three readers, and a check
	 * standing in one of them leaves the other two open.
	 */
	public static function getMigrationControlPanelUrl(): string
	{
		return self::asPortalPath(
			(string)Option::get('mail', self::MIGRATION_CONTROL_PANEL_URL_OPTION, ''),
			self::MIGRATION_CONTROL_PANEL_URL_STUB,
		);
	}

	/**
	 * Where the "Learn more" link of a migration message leads. The address is assembled and not stored,
	 * so the article opens on the helpdesk of the language the person reads the interface in; the option
	 * says which article, and the interface library says which helpdesk.
	 *
	 * Help lives in that library, and without it there is no address to give at all: the link then leads
	 * nowhere rather than to the helpdesk of a language nobody chose.
	 */
	public static function getMigrationHelpdeskUrl(): string
	{
		if (!Loader::includeModule('ui'))
		{
			return '';
		}

		$article = (string)Option::get(
			'mail',
			self::MIGRATION_HELPDESK_ARTICLE_OPTION,
			self::MIGRATION_HELPDESK_ARTICLE,
		);

		return Util::getHelpdeskUrl(true) . '/open/' . self::asArticleNumber($article) . '/';
	}

	/**
	 * An address inside the portal carries a path of the portal and nothing else. One decision goes out
	 * to the web page and to the mobile application alike, so another host, a scheme of the application,
	 * "javascript:" and the host-relative "//somewhere" are all answered with the address the feature
	 * ships with: the application hands "b24://" to a router of screens of its own instead of opening a
	 * page, and a page follows "//somewhere" to a site nobody meant.
	 */
	private static function asPortalPath(string $url, string $fallback): string
	{
		return preg_match('#^/(?![/\\\\])#', $url) === 1 ? $url : $fallback;
	}

	/**
	 * The host of the helpdesk is the library's to choose and no longer the option's, so nothing here
	 * guards against another site. What is left of the option is pasted into the path of the address,
	 * and an article is named by a number: anything else in that option would add a query, a climb out
	 * of the article or a piece of markup to a link the clients hand to a person.
	 */
	private static function asArticleNumber(string $article): string
	{
		return preg_match('/^[0-9]+$/D', $article) === 1 ? $article : self::MIGRATION_HELPDESK_ARTICLE;
	}
}
