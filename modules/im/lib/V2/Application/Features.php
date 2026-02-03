<?php

namespace Bitrix\Im\V2\Application;

use Bitrix\Im\Call\Integration\Zoom;
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
		public readonly bool $isNotificationsStandalone,
		public readonly bool $isCopilotSelectModelAvailable,
		public readonly bool $teamsInStructureAvailable,
		public readonly bool $isDesktopRedirectAvailable,
		public readonly bool $aiAssistantAvailable,
		public readonly bool $isCopilotMentionAvailable,
		public readonly bool $aiFileTranscriptionAvailable,
		public readonly bool $isTasksRecentListAvailable,
		public readonly bool $videoNoteAvailable,
		public readonly bool $mentionAllAvailable,
		public readonly bool $unreadRecentModeAvailable,
		public readonly bool $isMultipleReactionsAvailable,
		public readonly bool $aiAssistantMcpSelectorAvailable,
		public readonly bool $videoNoteTranscriptionAvailable,
		public readonly bool $stickersAvailable,
		public readonly bool $isCopilotReasoningAvailable,
		public readonly bool $isTextFormatToolbarAvailable,
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
			!Settings::isLegacyChatActivated(),
			Structure::isSyncAvailable(),
			CopilotChat::isActive(),
			CopilotChat::isAvailable(),
			Option::get('im', 'im_link_url_migration', 'N') === 'Y',
			Option::get('im', 'im_link_file_migration', 'N') === 'Y',
			Documents::getResumesOfCallStatus() === Documents::ENABLED,
			Zoom::isActive(),
			Zoom::isAvailable(),
			self::isImOpenLinesV2Available(),
			self::isGiphyAvailable(),
			Collab::isAvailable(),
			Collab::isCreationAvailable(),
			CollaberService::getInstance()->isEnabledCollabersInvitation(),
			self::isInviteByPhoneAvailable(),
			self::isInviteByLinkAvailable(),
			DocumentSign::isAvailable(),
			Invitation::isAvailable(),
			Invitation::isChangeLanguageAvailable(),
			self::isVoteCreationAvailable(),
			self::isMessagesAutoDeleteEnabled(),
			self::isNotificationsStandalone(),
			self::isCopilotSelectModelAvailable(),
			Structure::isTeamsAvailable(),
			self::isDesktopRedirectAvailable(),
			self::isAiAssistantAvailable(),
			self::isCopilotMentionAvailable(),
			self::isAiFileTranscriptionAvailable(),
			self::isTasksRecentListAvailable(),
			self::isVideoNoteAvailable(),
			self::isMentionAllAvailable(),
			self::isUnreadRecentModeAvailable(),
			self::isMultipleReactionsAvailable(),
			self::isAiAssistantMcpSelectorAvailable(),
			self::isVideoNoteTranscriptionAvailable(),
			self::isStickersAvailable(),
			self::isCopilotReasoningAvailable(),
			self::isTextFormatToolbarAvailable(),
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

	private static function isNotificationsStandalone(): bool
	{
		return Option::get('im', '~is_notifications_standalone', 'N') === 'Y';
	}

	public static function isMessagesAutoDeleteEnabled(): bool
	{
		return Option::get('im', 'isAutoDeleteMessagesEnabled', 'Y') === 'Y';
	}

	public static function isCopilotSelectModelAvailable(): bool
	{
		return Option::get('im', 'copilot_select_model_activated', 'N') === 'Y';
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

		if (\CUserOptions::GetOption('im', 'copilot_mention_user', 'N') === 'Y')
		{
			return true;
		}

		return Option::get('im', 'copilot_mention', 'N') === 'Y';
	}

	public static function isAiFileTranscriptionAvailable(): bool
	{
		return Option::get('im', 'file_transcription_available', 'N') === 'Y'
			&& ServiceLocator::getInstance()->get(Restriction::class)->isTranscriptionActive()
		;
	}

	public static function isVideoNoteTranscriptionAvailable(): bool
	{
		return Option::get('im', 'video_note_transcription_available', 'N') === 'Y'
			&& ServiceLocator::getInstance()->get(Restriction::class)->isTranscriptionActive()
		;
	}

	public static function isUnreadRecentModeAvailable(): bool
	{
		return Option::get('im', 'unread_recent_mode_available', 'N') === 'Y';
	}

	public static function isTasksRecentListAvailable(): bool
	{
		return
			Option::get('im', 'is_tasks_recent_list_available', 'Y') === 'Y'
			&& Loader::includeModule('tasks')
		;
	}

	public static function isVideoNoteAvailable(): bool
	{
		return Option::get('im', 'video_note_available', 'N') === 'Y';
	}

	public static function isMentionAllAvailable(): bool
	{
		return Option::get('im', 'mention_all_available', 'N') === 'Y';
	}

	public static function isMultipleReactionsAvailable(): bool
	{
		return Option::get('im', 'multiple_reactions_available', 'N') === 'Y';
	}

	public static function isTranscriptionEmotionsAvailable(): bool
	{
		return Option::get('im', 'transcription_emotions_available', 'N') === 'Y';
	}

	public static function isStickersAvailable(): bool
	{
		return Option::get('im', 'stickers_available', 'N') === 'Y';
	}

	public static function isCopilotReasoningAvailable(): bool
	{
		return Option::get('im', 'copilot_reasoning_available', 'N') === 'Y';
	}

	public static function isAiAssistantMcpSelectorAvailable(): bool
	{
		return Option::get('im', 'ai_assistant_mcp_selector_available', 'N') === 'Y';
	}

	public static function isTextFormatToolbarAvailable(): bool
	{
		return Option::get('im', 'text_format_toolbar_available', 'N') === 'Y';
	}
}
