<?php

namespace Bitrix\Im\V2\Application;

use Bitrix\Im\Integration\Socialservices\Zoom;
use Bitrix\Im\Integration\Disk\Documents;
use Bitrix\Im\Settings;
use Bitrix\Im\V2\Chat\CopilotChat;
use Bitrix\Im\V2\Integration\AI\Restriction;
use Bitrix\Im\V2\Integration\AiAssistant\AiAssistantService;
use Bitrix\Im\V2\Integration\Extranet\CollaberService;
use Bitrix\Im\V2\Integration\HumanResources\Structure;
use Bitrix\Im\V2\Integration\Intranet\Invitation;
use Bitrix\Im\V2\Integration\Sign\DocumentSign;
use Bitrix\Im\V2\Integration\Socialnetwork\Collab\Collab;
use Bitrix\ImBot\Bot\Giphy;
use Bitrix\Main\Config\Option;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Loader;

class Features
{
	private static self $currentFeatures;

	public function __construct(
		public readonly bool $chatV2,
		public readonly bool $chatDepartments,
		public readonly bool $copilotActive,
		public readonly bool $copilotAvailable,
		public readonly bool $copilotHistoryAccess,
		public readonly bool $sidebarLinks,
		public readonly bool $sidebarFiles,
		public readonly bool $sidebarBriefs,
		public readonly bool $zoomActive,
		public readonly bool $zoomAvailable,
		public readonly bool $openLinesV2,
		public readonly bool $giphyAvailable,
		public readonly bool $collabAvailable,
		public readonly bool $collabCreationAvailable,
		public readonly bool $enabledCollabersInvitation,
		public readonly bool $inviteByPhoneAvailable,
		public readonly bool $inviteByLinkAvailable,
		public readonly bool $documentSignAvailable,
		public readonly bool $intranetInviteAvailable,
		public readonly bool $changeInviteLanguageAvailable,
		public readonly bool $voteCreationAvailable,
		public readonly bool $messagesAutoDeleteEnabled,
		public readonly bool $isCopilotSelectModelAvailable,
		public readonly bool $teamsInStructureAvailable,
		public readonly bool $isDesktopRedirectAvailable,
		public readonly bool $aiAssistantAvailable,
		public readonly bool $isCopilotMentionAvailable,
		public readonly bool $aiFileTranscriptionAvailable,
		public readonly bool $chatSharingLinkAvailable,
		public readonly bool $isTasksRecentListAvailable,
		public readonly bool $unreadRecentModeAvailable,
		public readonly bool $aiAssistantMcpSelectorAvailable,
		public readonly bool $videoNoteTranscriptionAvailable,
		public readonly bool $isCopilotReasoningAvailable,
		public readonly bool $isTextFormatToolbarAvailable,
		public readonly bool $isCounterV3Available,
		public readonly bool $isCopilotFileUploadAvailable,
		public readonly bool $isMountedTasksCardAvailable,
		public readonly bool $isBitrixGptV2Available,
		public readonly bool $isAddingUserByMentionAvailable,
	){}

	public static function get(): self
	{
		if (!isset(self::$currentFeatures))
		{
			self::$currentFeatures = self::createCurrent();
		}

		return self::$currentFeatures;
	}

	private static function createCurrent(): self
	{
		return new self(
			chatV2: !Settings::isLegacyChatActivated(),
			chatDepartments: Structure::isSyncAvailable(),
			copilotActive: CopilotChat::isActive(),
			copilotAvailable: CopilotChat::isAvailable(),
			copilotHistoryAccess: CopilotChat::isHistoryAvailable(),
			sidebarLinks: Option::get('im', 'im_link_url_migration', 'N') === 'Y',
			sidebarFiles: Option::get('im', 'im_link_file_migration', 'N') === 'Y',
			sidebarBriefs: Documents::getResumesOfCallStatus() === Documents::ENABLED,
			zoomActive: Zoom::isActive(),
			zoomAvailable: Zoom::isAvailable(),
			openLinesV2: self::isImOpenLinesV2Available(),
			giphyAvailable: self::isGiphyAvailable(),
			collabAvailable: Collab::isAvailable(),
			collabCreationAvailable: Collab::isCreationAvailable(),
			enabledCollabersInvitation: CollaberService::getInstance()->isEnabledCollabersInvitation(),
			inviteByPhoneAvailable: self::isInviteByPhoneAvailable(),
			inviteByLinkAvailable: self::isInviteByLinkAvailable(),
			documentSignAvailable: DocumentSign::isAvailable(),
			intranetInviteAvailable: Invitation::isAvailable(),
			changeInviteLanguageAvailable: Invitation::isChangeLanguageAvailable(),
			voteCreationAvailable: self::isVoteCreationAvailable(),
			messagesAutoDeleteEnabled: self::isMessagesAutoDeleteEnabled(),
			isCopilotSelectModelAvailable: self::isCopilotSelectModelAvailable(),
			teamsInStructureAvailable: Structure::isTeamsAvailable(),
			isDesktopRedirectAvailable: self::isDesktopRedirectAvailable(),
			aiAssistantAvailable: self::isAiAssistantAvailable(),
			isCopilotMentionAvailable: self::isCopilotMentionAvailable(),
			aiFileTranscriptionAvailable: self::isAiFileTranscriptionAvailable(),
			chatSharingLinkAvailable: self::isChatSharingLinkAvailable(),
			isTasksRecentListAvailable: self::isTasksRecentListAvailable(),
			unreadRecentModeAvailable: self::isUnreadRecentModeAvailable(),
			aiAssistantMcpSelectorAvailable: self::isAiAssistantMcpSelectorAvailable(),
			videoNoteTranscriptionAvailable: self::isVideoNoteTranscriptionAvailable(),
			isCopilotReasoningAvailable: self::isCopilotReasoningAvailable(),
			isTextFormatToolbarAvailable: self::isTextFormatToolbarAvailable(),
			isCounterV3Available: self::isCounterV3Available(),
			isCopilotFileUploadAvailable: self::isCopilotFileUploadAvailable(),
			isMountedTasksCardAvailable: self::isMountedTasksCardAvailable(),
			isBitrixGptV2Available: self::isBitrixGptV2Available(),
			isAddingUserByMentionAvailable: self::isAddingUserByMentionAvailable(),
		);
	}

	private static function isGiphyAvailable(): bool
	{
		return Loader::includeModule('imbot')
			&& method_exists(Giphy::class, 'isAvailable')
			&& Giphy::isAvailable()
		;
	}

	private static function isInviteByPhoneAvailable(): bool
	{
		return Loader::includeModule("bitrix24")
			&& Option::get('bitrix24', 'phone_invite_allowed', 'N') === 'Y'
		;
	}

	private static function isInviteByLinkAvailable(): bool
	{
		return true;
	}

	private static function isImOpenLinesV2Available(): bool
	{
		if (Loader::includeModule('imopenlines'))
		{
			return \Bitrix\ImOpenLines\V2\Settings\Settings::isV2Available();
		}

		return false;
	}

	private static function isVoteCreationAvailable(): bool
	{
		return Loader::includeModule('vote')
			&& class_exists('\\Bitrix\\Vote\\Config\\Feature')
			&& \Bitrix\Vote\Config\Feature::instance()->isImIntegrationEnabled()
		;
	}

	public static function isMessagesAutoDeleteEnabled(): bool
	{
		return Option::get('im', 'isAutoDeleteMessagesEnabled', 'Y') === 'Y';
	}

	public static function isCopilotSelectModelAvailable(): bool
	{
		return true;
	}

	public static function isDesktopRedirectAvailable(): bool
	{
		return Option::get('im', 'desktop_redirect_available', 'N') === 'Y';
	}

	public static function isAiAssistantAvailable(): bool
	{
		return ServiceLocator::getInstance()->get(AiAssistantService::class)->isAiAssistantAvailable();
	}

	public static function isCopilotMentionAvailable(): bool
	{
		if (!CopilotChat::isActive())
		{
			return false;
		}

		return true;
	}

	public static function isAiFileTranscriptionAvailable(): bool
	{
		return ServiceLocator::getInstance()->get(Restriction::class)->isTranscriptionActive();
	}

	public static function isChatSharingLinkAvailable(): bool
	{
		if (\CUserOptions::GetOption('im', 'chat_sharing_link_available_user', 'N') === 'Y')
		{
			return true;
		}

		return Option::get('im', 'chat_sharing_link_available', 'N') === 'Y';
	}

	public static function isVideoNoteTranscriptionAvailable(): bool
	{
		return ServiceLocator::getInstance()->get(Restriction::class)->isTranscriptionActive();
	}

	public static function isUnreadRecentModeAvailable(): bool
	{
		return Option::get('im', 'unread_recent_mode_available', 'N') === 'Y';
	}

	public static function isTasksRecentListAvailable(): bool
	{
		return Loader::includeModule('tasks');
	}

	public static function isCopilotReasoningAvailable(): bool
	{
		return true;
	}

	public static function isAiAssistantMcpSelectorAvailable(): bool
	{
		return Option::get('im', 'ai_assistant_mcp_selector_available', 'N') === 'Y';
	}

	public static function isTextFormatToolbarAvailable(): bool
	{
		return Option::get('im', 'text_format_toolbar_available', 'N') === 'Y';
	}

	public static function isCounterV3Available(): bool
	{
		return Option::get('im', 'counter_v3_available', 'N') === 'Y';
	}

	public static function isCopilotFileUploadAvailable(): bool
	{
		return Option::get('im', 'copilot_file_upload_available', 'N') === 'Y';
	}

	public static function isMountedTasksCardAvailable(): bool
	{
		return Option::get('im', 'mounted_tasks_card_available', 'N') === 'Y';
	}

	public static function isBitrixGptV2Available(): bool
	{
		return Option::get('aiassistant', 'show_chat_in_right_panel', 'N') === 'Y';
	}

	public static function isAddingUserByMentionAvailable(): bool
	{
		return Option::get('im', 'adding_user_by_mention_available', 'N') === 'Y';
	}
}
