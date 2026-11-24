<?php

namespace Bitrix\Im\V2\Application;

use Bitrix\AiAssistant\Config\Restriction as AiAssistantRestriction;
use Bitrix\Im\Common;
use Bitrix\Im\Integration\Socialservices\Zoom;
use Bitrix\Im\Integration\Vibecodeconnector\Catalog;
use Bitrix\Im\Integration\Disk\Documents;
use Bitrix\Im\Public\Features\MentionAddToChatPopup;
use Bitrix\Im\Public\Features\AddingUserByMention;
use Bitrix\Im\Public\Features\AiAssistantMcpSelectionRestore;
use Bitrix\Im\Public\Features\AiAssistantRegenerate;
use Bitrix\Im\Public\Features\AirDesign;
use Bitrix\Im\Public\Features\AiTaskCreationFromMessages;
use Bitrix\Im\Public\Features\AttachChatToProject;
use Bitrix\Im\Public\Features\BackgroundAudioPlayback;
use Bitrix\Im\Public\Features\BitrixGptScrollPin;
use Bitrix\Im\Public\Features\ChatBotCommands;
use Bitrix\Im\Public\Features\ChatFolderAll;
use Bitrix\Im\Public\Features\ChatFoldersWeb;
use Bitrix\Im\Public\Features\ChatSharingLink;
use Bitrix\Im\Public\Features\ChatShellNavigationWeb;
use Bitrix\Im\Public\Features\ChatThreads;
use Bitrix\Im\Public\Features\CollabPreviewSource;
use Bitrix\Im\Public\Features\ContactCard;
use Bitrix\Im\Public\Features\CounterV3;
use Bitrix\Im\Public\Features\DesktopRedirect;
use Bitrix\Im\Public\Features\DiskImHeadIndexing;
use Bitrix\Im\Public\Features\EditMessageFiles;
use Bitrix\Im\Public\Features\EmojiSearch;
use Bitrix\Im\Public\Features\ExternalChatMessageForwarding;
use Bitrix\Im\Public\Features\ForwardMultipleRecipients;
use Bitrix\Im\Public\Features\FolderMute;
use Bitrix\Im\Public\Features\FolderScopedPins;
use Bitrix\Im\Public\Features\Markdown;
use Bitrix\Im\Public\Features\MeetingsFolder;
use Bitrix\Im\Public\Features\MessageBlockFooter;
use Bitrix\Im\Public\Features\MessageDateNavigation;
use Bitrix\Im\Public\Features\MessageFileAudioBlock;
use Bitrix\Im\Public\Features\MessageFormBlock;
use Bitrix\Im\Public\Features\MessageSearch;
use Bitrix\Im\Public\Features\MessageSearchIndexing;
use Bitrix\Im\Public\Features\MentionInstantSearch;
use Bitrix\Im\Public\Features\MountedTasksCard;
use Bitrix\Im\Public\Features\PersonalPin;
use Bitrix\Im\Public\Features\ReplyWithMedia;
use Bitrix\Im\Public\Features\QuickActions;
use Bitrix\Im\Public\Features\ReadAllButton;
use Bitrix\Im\Public\Features\ScheduledMessages;
use Bitrix\Im\Public\Features\TaskResultButton;
use Bitrix\Im\Public\Features\TextFormatToolbar;
use Bitrix\Im\Public\Features\UploadCaptionMentionsFormat;
use Bitrix\Im\Public\Features\VibecodeAppChatBanner;
use Bitrix\Im\Public\Features\VibecodeAppOpenButton;
use Bitrix\Im\Public\Features\VibecodeAppsFolder;
use Bitrix\Im\Public\Features\VideoNoteCaption;
use Bitrix\Im\Public\Features\VideoNotePlaybackRate;
use Bitrix\Im\Public\Features\VoiceMessageRecording;
use Bitrix\Im\Public\Features\VoiceMessageServerConversion;
use Bitrix\Im\Settings;
use Bitrix\Im\V2\Chat\CopilotChat;
use Bitrix\Im\V2\Entity\User\User;
use Bitrix\Im\V2\Guest\GuestService;
use Bitrix\Im\V2\Integration\AI\Restriction;
use Bitrix\Im\V2\Integration\AiAssistant\AiAssistantService;
use Bitrix\Im\V2\Integration\AiAssistant\WebSearchService;
use Bitrix\Im\V2\Integration\Extranet\CollaberService;
use Bitrix\Im\V2\Integration\HumanResources\Structure;
use Bitrix\Im\V2\Integration\Intranet\Invitation;
use Bitrix\Im\V2\Integration\Sign\DocumentSign;
use Bitrix\Im\V2\Integration\Socialnetwork\Collab\Collab;
use Bitrix\Im\V2\Link\Pin\PinLimits;
use Bitrix\Im\V2\Service\Locator;
use Bitrix\ImBot\Bot\Giphy;
use Bitrix\Main\Config\Feature;
use Bitrix\Main\Config\Option;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Loader;
use Bitrix\StaffTrack\Public\Provider\CheckInSettingsProvider;

/**
 * Almost every flag here is portal-wide, but isVideoNoteDownloadAvailable answers for the current user.
 * The set is therefore bound to the user it was built for and must not be cached or reused across users.
 */
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
		public readonly bool $teamsInStructureAvailable,
		public readonly bool $isDesktopRedirectAvailable,
		public readonly bool $aiAssistantAvailable,
		public readonly bool $isCopilotMentionAvailable,
		public readonly bool $aiFileTranscriptionAvailable,
		public readonly bool $chatSharingLinkAvailable,
		public readonly bool $isTasksRecentListAvailable,
		public readonly bool $aiAssistantMcpSelectorAvailable,
		public readonly bool $isAiAssistantMcpSelectionRestoreAvailable,
		public readonly bool $videoNoteTranscriptionAvailable,
		public readonly bool $isTextFormatToolbarAvailable,
		public readonly bool $isCounterV3Available,
		public readonly bool $isCopilotFileUploadAvailable,
		public readonly bool $isBitrixGptUploadPolicyAvailable,
		public readonly bool $isAiRequestTemplatesAvailable,
		public readonly bool $isMountedTasksCardAvailable,
		public readonly bool $isBitrixGptV2Available,
		public readonly bool $isBitrixGptResearchAvailable,
		public readonly bool $isMessageBuilderAvailable,
		public readonly bool $isAddingUserByMentionAvailable,
		public readonly bool $isAddToChatPopupAvailable,
		public readonly bool $isExternalChatMessageForwardingAvailable,
		public readonly bool $isNestedChatAvailable,
		public readonly bool $isChatWithGuestsAvailable,
		public readonly bool $isCopilotForceSearchAvailable,
		public readonly bool $isCopilotWebSearchEnabledByAdmin,
		public readonly bool $isCopilotWebSearchAllowedByTariff,
		public readonly bool $isAiAssistantFeedbackAvailable,
		public readonly bool $isAiAssistantAgentModeAvailable,
		public readonly bool $isChatFoldersWebAvailable,
		public readonly bool $isChatFolderAllAvailable,
		public readonly bool $isChatShellNavigationWebAvailable,
		public readonly bool $isAiAssistantRegenerateAvailable,
		public readonly bool $collabPreviewSourceEnabled,
		public readonly bool $isMarkdownAvailable,
		public readonly bool $isCopilotDraftChatAvailable,
		public readonly bool $isAttachChatToProjectAvailable,
		public readonly bool $isAiTaskCreationFromMessagesAvailable,
		public readonly bool $isAirDesignEnabled,
		public readonly bool $isMessageDateNavigationAvailable,
		public readonly bool $isReplyWithMediaAvailable,
		public readonly bool $isTaskResultButtonAvailable,
		public readonly bool $isMessageFormBlockAvailable,
		public readonly bool $isMessageBlockFooterAvailable,
		public readonly bool $isBitrixGptScrollPinAvailable,
		public readonly bool $isBitrixGptStopGenerationAvailable,
		public readonly bool $isMessageFileAudioBlockAvailable,
		public readonly bool $isChatBotCommandsAvailable,
		public readonly bool $isUploadCaptionMentionsFormatAvailable,
		public readonly bool $isEmojiSearchAvailable,
		public readonly bool $isBackgroundAudioPlaybackAvailable,
		public readonly bool $isVideoNotePlaybackRateAvailable,
		public readonly bool $isMentionInstantSearchAvailable,
		public readonly bool $isVideoNoteCaptionAvailable,
		public readonly bool $isVoiceMessageRecordingAvailable,
		public readonly bool $isEditMessageFilesAvailable,
		public readonly bool $isVibecodeAppOpenButtonAvailable,
		public readonly bool $isScheduledMessagesAvailable,
		public readonly bool $isVibecodeAppChatBannerAvailable,
		public readonly bool $isForwardMultipleRecipientsAvailable,
		public readonly bool $isContactCardAvailable,
		public readonly bool $messageSearchAvailable,
		public readonly bool $isChatThreadsAvailable,
		public readonly bool $isFolderMuteAvailable,
		public readonly bool $isCheckInAvailable,
		public readonly bool $isFolderScopedPinsAvailable,
		public readonly bool $isPersonalPinAvailable,
		public readonly bool $isVideoNoteDownloadAvailable,
		public readonly bool $bitrixGptVoiceWidgetActive,
		public readonly bool $isQuickActionsAvailable,
		public readonly bool $isReadAllButtonAvailable,
		/**
		 * The only member of the set that is not a flag. The mobile application reads the whole set
		 * through one proxy of its own module, so this is the way the pin limits reach it; the web
		 * messenger takes the same section from its config.
		 *
		 * @var array{maxPinsPerChat: int, maxPersonalPinsPerChat: int}
		 */
		public readonly array $pinLimits,
		public readonly bool $isAiAssistantRegenerateSignalsReady,
		public readonly bool $isGuestOutgoingCallAvailable,
		public readonly bool $isGuestOutgoingPrivateCallAvailable,
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
			teamsInStructureAvailable: Structure::isTeamsAvailable(),
			isDesktopRedirectAvailable: self::isDesktopRedirectAvailable(),
			aiAssistantAvailable: self::isAiAssistantAvailable(),
			isCopilotMentionAvailable: self::isCopilotMentionAvailable(),
			aiFileTranscriptionAvailable: self::isAiFileTranscriptionAvailable(),
			chatSharingLinkAvailable: self::isChatSharingLinkAvailable(),
			isTasksRecentListAvailable: self::isTasksRecentListAvailable(),
			aiAssistantMcpSelectorAvailable: self::isAiAssistantMcpSelectorAvailable(),
			isAiAssistantMcpSelectionRestoreAvailable: self::isAiAssistantMcpSelectionRestoreAvailable(),
			videoNoteTranscriptionAvailable: self::isVideoNoteTranscriptionAvailable(),
			isTextFormatToolbarAvailable: self::isTextFormatToolbarAvailable(),
			isCounterV3Available: self::isCounterV3Available(),
			isCopilotFileUploadAvailable: self::isCopilotFileUploadAvailable(),
			isBitrixGptUploadPolicyAvailable: self::isBitrixGptUploadPolicyAvailable(),
			isAiRequestTemplatesAvailable: self::isAiRequestTemplatesAvailableForUser(),
			isMountedTasksCardAvailable: self::isMountedTasksCardAvailable(),
			isBitrixGptV2Available: self::isBitrixGptV2Available(),
			isBitrixGptResearchAvailable: self::isBitrixGptResearchAvailable(),
			isMessageBuilderAvailable: self::isMessageBuilderAvailable(),
			isAddingUserByMentionAvailable: self::isAddingUserByMentionAvailable(),
			isAddToChatPopupAvailable: self::isAddToChatPopupAvailable(),
			isExternalChatMessageForwardingAvailable: self::isExternalChatMessageForwardingAvailable(),
			isNestedChatAvailable: Collab::isNewProjectsAvailable(),
			isChatWithGuestsAvailable: self::isChatWithGuestsAvailable(GuestService::getInstance()->getCurrentInviterId()),
			isCopilotForceSearchAvailable: self::isCopilotForceSearchAvailable(),
			isCopilotWebSearchEnabledByAdmin: self::isCopilotWebSearchEnabledByAdmin(),
			isCopilotWebSearchAllowedByTariff: self::isCopilotWebSearchAllowedByTariff(),
			isAiAssistantFeedbackAvailable: self::isAiAssistantFeedbackAvailable(),
			isAiAssistantAgentModeAvailable: self::isAiAssistantAgentModeAvailable(),
			isChatFoldersWebAvailable: self::isChatFoldersWebAvailableForUser(),
			isChatFolderAllAvailable: self::isChatFolderAllAvailable(),
			isChatShellNavigationWebAvailable: self::isChatShellNavigationWebAvailableForUser(),
			isAiAssistantRegenerateAvailable: self::isAiAssistantRegenerateAvailable(),
			collabPreviewSourceEnabled: self::isCollabPreviewSourceEnabled(),
			isMarkdownAvailable: self::isMarkdownAvailable(),
			isCopilotDraftChatAvailable: self::isCopilotDraftChatAvailable(),
			isAttachChatToProjectAvailable: self::isAttachChatToProjectAvailable(),
			isAiTaskCreationFromMessagesAvailable: self::isAiTaskCreationFromMessagesAvailable(),
			isAirDesignEnabled: self::isAirDesignEnabled(),
			isMessageDateNavigationAvailable: self::isMessageDateNavigationAvailable(),
			isReplyWithMediaAvailable: self::isReplyWithMediaAvailable(),
			isTaskResultButtonAvailable: self::isTaskResultButtonAvailable(),
			isMessageFormBlockAvailable: self::isMessageFormBlockAvailable(),
			isMessageBlockFooterAvailable: self::isMessageBlockFooterAvailable(),
			isBitrixGptScrollPinAvailable: self::isBitrixGptScrollPinAvailable(),
			isBitrixGptStopGenerationAvailable: self::isBitrixGptStopGenerationAvailable(),
			isMessageFileAudioBlockAvailable: self::isMessageFileAudioBlockAvailable(),
			isChatBotCommandsAvailable: self::isChatBotCommandsAvailable(),
			isUploadCaptionMentionsFormatAvailable: self::isUploadCaptionMentionsFormatAvailable(),
			isEmojiSearchAvailable: self::isEmojiSearchAvailable(),
			isBackgroundAudioPlaybackAvailable: self::isBackgroundAudioPlaybackAvailable(),
			isVideoNotePlaybackRateAvailable: self::isVideoNotePlaybackRateAvailable(),
			isMentionInstantSearchAvailable: self::isMentionInstantSearchAvailable(),
			isVideoNoteCaptionAvailable: self::isVideoNoteCaptionAvailable(),
			isVoiceMessageRecordingAvailable: self::isVoiceMessageRecordingAvailable(),
			isEditMessageFilesAvailable: self::isEditMessageFilesAvailable(),
			isVibecodeAppOpenButtonAvailable: self::isVibecodeAppOpenButtonAvailable(),
			isScheduledMessagesAvailable: self::isScheduledMessagesAvailable(),
			isVibecodeAppChatBannerAvailable: self::isVibecodeAppChatBannerAvailableForUser(),
			isForwardMultipleRecipientsAvailable: self::isForwardMultipleRecipientsAvailable(),
			isContactCardAvailable: self::isContactCardAvailable(),
			messageSearchAvailable: self::isMessageSearchAvailableForUser(),
			isChatThreadsAvailable: self::isChatThreadsAvailable(),
			isFolderMuteAvailable: self::isFolderMuteAvailable(),
			isCheckInAvailable: self::isCheckInAvailable(),
			isFolderScopedPinsAvailable: self::isFolderScopedPinsAvailable(),
			isPersonalPinAvailable: self::isPersonalPinAvailable(),
			isVideoNoteDownloadAvailable: self::isVideoNoteDownloadAvailable(),
			bitrixGptVoiceWidgetActive: self::isBitrixGptVoiceWidgetActive(),
			isQuickActionsAvailable: self::isQuickActionsAvailable(),
			isReadAllButtonAvailable: self::isReadAllButtonAvailable(),
			pinLimits: PinLimits::getClientLimits(),
			isAiAssistantRegenerateSignalsReady: self::isAiAssistantRegenerateSignalsReady(),
			isGuestOutgoingCallAvailable: self::isGuestOutgoingCallAvailable(),
			isGuestOutgoingPrivateCallAvailable: self::isGuestOutgoingPrivateCallAvailable(),
		);
	}

	/**
	 * Availability of the check-in entry point for the current user. The gates belong to the check-in
	 * domain, so they are asked as one question there; the module stays optional, and a portal without
	 * it - or with a version of it that predates the aggregate - gets false.
	 */
	private static function isCheckInAvailable(): bool
	{
		if (!Loader::includeModule('stafftrack'))
		{
			return false;
		}

		return method_exists(CheckInSettingsProvider::class, 'isCheckInAvailable')
			&& (new CheckInSettingsProvider())->isCheckInAvailable()
		;
	}

	/**
	 * Whether a guest may start a call. Owned by the call module ({@see \Bitrix\Call\Settings}); the
	 * messenger only needs the answer to decide on the call button of the chat header.
	 */
	private static function isGuestOutgoingCallAvailable(): bool
	{
		return Loader::includeModule('call') && \Bitrix\Call\Settings::isGuestOutgoingCallEnabled();
	}

	/**
	 * Whether a guest may start a call at a bare user id - a one-to-one dialogue rather than a chat. The
	 * answer above narrowed by the call scheme that serves this shape; the reasons belong to the call
	 * module ({@see \Bitrix\Call\Settings::isGuestOutgoingPrivateCallEnabled}).
	 */
	private static function isGuestOutgoingPrivateCallAvailable(): bool
	{
		return Loader::includeModule('call') && \Bitrix\Call\Settings::isGuestOutgoingPrivateCallEnabled();
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

	private static function isQuickActionsAvailable(): bool
	{
		return Feature::isEnabled(QuickActions::class);
	}

	private static function isReadAllButtonAvailable(): bool
	{
		return Feature::isEnabled(ReadAllButton::class);
	}

	public static function isMessagesAutoDeleteEnabled(): bool
	{
		return Option::get('im', 'isAutoDeleteMessagesEnabled', 'Y') === 'Y';
	}

	public static function isDesktopRedirectAvailable(): bool
	{
		return Feature::isEnabled(DesktopRedirect::class);
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

	public static function isBitrixGptVoiceWidgetActive(): bool
	{
		return (new Restriction())->isBitrixGptVoiceWidgetActive();
	}

	public static function isChatSharingLinkAvailable(): bool
	{
		if (\CUserOptions::GetOption('im', 'chat_sharing_link_available_user', 'N') === 'Y')
		{
			return true;
		}

		return Feature::isEnabled(ChatSharingLink::class);
	}

	public static function isVideoNoteTranscriptionAvailable(): bool
	{
		return ServiceLocator::getInstance()->get(Restriction::class)->isTranscriptionActive();
	}

	public static function isTasksRecentListAvailable(): bool
	{
		return Loader::includeModule('tasks');
	}

	public static function isAiAssistantMcpSelectorAvailable(): bool
	{
		if (self::isAiAssistantRestricted())
		{
			return false;
		}

		return Option::get('im', 'ai_assistant_mcp_selector_available', 'N') === 'Y';
	}

	public static function isAiAssistantMcpSelectionRestoreAvailable(): bool
	{
		if (self::isAiAssistantRestricted())
		{
			return false;
		}

		return Feature::isEnabled(AiAssistantMcpSelectionRestore::class);
	}

	public static function isTextFormatToolbarAvailable(): bool
	{
		return Feature::isEnabled(TextFormatToolbar::class);
	}

	public static function isCounterV3Available(): bool
	{
		return Feature::isEnabled(CounterV3::class);
	}

	public static function isCopilotFileUploadAvailable(): bool
	{
		return ServiceLocator::getInstance()
			->get(AiAssistantService::class)
			->isBitrixGptV2Available('copilot_file_upload_available')
		;
	}

	public static function isBitrixGptUploadPolicyAvailable(): bool
	{
		return ServiceLocator::getInstance()
			->get(AiAssistantService::class)
			->isBitrixGptV2Available('bitrixgpt_upload_policy_available')
		;
	}

	/**
	 * The value of the key itself, with no user taken into account.
	 * To tell whether the library is offered to someone, use {@see self::isAiRequestTemplatesAvailableForUser()}.
	 */
	public static function isAiRequestTemplatesAvailable(): bool
	{
		return ServiceLocator::getInstance()
			->get(AiAssistantService::class)
			->isAiRequestTemplatesAvailable()
		;
	}

	/**
	 * An extranet user is a legitimate member of the assistant chat - the chat is an ordinary group chat,
	 * and nothing in it is forbidden to them. The scenario library is simply not offered to them, so the
	 * rule belongs to the feature set and not to the permissions of the chat.
	 *
	 * The portal half of the answer always speaks about the current user, so the argument narrows
	 * the user type check only and is meant for the current user.
	 *
	 * @param int|null $userId the current user by default
	 */
	public static function isAiRequestTemplatesAvailableForUser(?int $userId = null): bool
	{
		// no authorized user at all comes back as `false` from Common, hence the cast
		$userId ??= (int)Common::getUserId();

		return self::isAiRequestTemplatesAvailable() && !($userId > 0 && User::getInstance($userId)->isExtranet());
	}

	public static function isMountedTasksCardAvailable(): bool
	{
		return Feature::isEnabled(MountedTasksCard::class);
	}

	public static function isBitrixGptV2Available(): bool
	{
		return ServiceLocator::getInstance()
			->get(AiAssistantService::class)
			->isBitrixGptV2Available('bitrixgpt_v2_available')
		;
	}

	/**
	 * The single switch of Research mode is `im.bitrixgpt_research_available` (portal or user, behind
	 * the BitrixGPT V2 gate). Only APIs that already exist in released modules are called, so this
	 * module can ship before or after ai and aiassistant.
	 */
	public static function isBitrixGptResearchAvailable(): bool
	{
		return ServiceLocator::getInstance()
				->get(AiAssistantService::class)
				->isBitrixGptV2Available('bitrixgpt_research_available')
			&& self::isCopilotWebSearchEnabledByAdmin()
			&& self::isCopilotWebSearchAllowedByTariff()
		;
	}

	public static function isBitrixGptStopGenerationAvailable(): bool
	{
		return ServiceLocator::getInstance()
			->get(AiAssistantService::class)
			->isBitrixGptV2Available('bitrixgpt_stop_generation_available')
		;
	}

	public static function isMessageBuilderAvailable(): bool
	{
		return Option::get('im', 'message_builder_available', 'N') === 'Y';
	}

	public static function isAddingUserByMentionAvailable(): bool
	{
		return Feature::isEnabled(AddingUserByMention::class);
	}

	public static function isAddToChatPopupAvailable(): bool
	{
		return Feature::isEnabled(MentionAddToChatPopup::class);
	}

	public static function isMessageDateNavigationAvailable(): bool
	{
		return Feature::isEnabled(MessageDateNavigation::class);
	}

	public static function isExternalChatMessageForwardingAvailable(): bool
	{
		return Feature::isEnabled(ExternalChatMessageForwarding::class);
	}

	public static function isForwardMultipleRecipientsAvailable(): bool
	{
		return Feature::isEnabled(ForwardMultipleRecipients::class);
	}

	public static function isChatWithGuestsAvailable(?int $inviterId = null): bool
	{
		if (Option::get('im', 'chat_with_guests_available', 'N') === 'Y')
		{
			return true;
		}

		return \CUserOptions::GetOption('im', 'chat_with_guests_available_user', 'N', $inviterId ?? false) === 'Y';
	}

	public static function isCopilotForceSearchAvailable(): bool
	{
		return ServiceLocator::getInstance()
			->get(AiAssistantService::class)
			->isBitrixGptV2Available('copilot_force_search_available')
		;
	}

	public static function isAiAssistantFeedbackAvailable(): bool
	{
		return Option::get('im', 'ai_assistant_feedback_available', 'N') === 'Y';
	}

	public static function isAiAssistantAgentModeAvailable(): bool
	{
		if (self::isAiAssistantRestricted())
		{
			return false;
		}

		return Option::get('im', 'ai_assistant_agent_mode_available', 'N') === 'Y';
	}

	private static function isAiAssistantRestricted(): bool
	{
		return Loader::includeModule('aiassistant')
			&& class_exists(AiAssistantRestriction::class)
			&& !AiAssistantRestriction::getInstance()->isAvailable();
	}

	public static function isChatFoldersWebAvailable(): bool
	{
		return Feature::isEnabled(ChatFoldersWeb::class);
	}

	/**
	 * The folders of a guest are nobody's: a guest sits in one chat of somebody else's portal, and the
	 * folder REST surfaces are deliberately left out of the guest allowlist
	 * ({@see \Bitrix\Im\V2\Controller\Filter\AuthorizationPrefilter::getAllowedGuestControllers}).
	 * Handing the feature to a guest client sends its messenger into the layout of the composite folder,
	 * whose list is loaded by one of those very surfaces - and the guest is left with no chat list at all.
	 *
	 * @param int|null $userId the current user by default
	 */
	public static function isChatFoldersWebAvailableForUser(?int $userId = null): bool
	{
		// no authorized user at all comes back as `false` from Common, hence the cast
		$userId ??= (int)Common::getUserId();

		return self::isChatFoldersWebAvailable() && !($userId > 0 && User::getInstance($userId)->isGuest());
	}

	public static function isChatFolderAllAvailable(): bool
	{
		return Feature::isEnabled(ChatFolderAll::class);
	}

	/**
	 * Muting a folder, the rule stored on the folder of the user
	 * ({@see \Bitrix\Im\V2\Folder\Mute\MuteService}). One option for the server and both
	 * clients; the folders themselves stay the condition above it - the rule has nowhere to live
	 * without them.
	 */
	public static function isFolderMuteAvailable(): bool
	{
		return Feature::isEnabled(FolderMute::class);
	}

	/**
	 * Pin sets that belong to a folder instead of to the chat list as a whole. Counted on the server
	 * per user and published to a client: with this one off the pin write fans a copy of the pin out
	 * to every eligible folder while the ChatPin event names no folder at all, so a folder set stops
	 * being an answer in a live session and a client has to know the write mode to pick its source.
	 * Independent of the folder flags as well - with this one off the pin write keeps today's
	 * behaviour whatever state the folders are in.
	 *
	 * @param int|null $userId the user whose pin write is being dispatched
	 */
	public static function isFolderScopedPinsAvailable(?int $userId = null): bool
	{
		if (Feature::isEnabled(FolderScopedPins::class))
		{
			return true;
		}

		return \CUserOptions::GetOption('im', 'folder_scoped_pins_available_user', 'N', $userId ?? false) === 'Y';
	}

	/**
	 * The shell moves the settings entry point into the chat list header, which only exists
	 * together with the folders, so the flag inherits their per-user rule
	 * ({@see self::isChatFoldersWebAvailableForUser}): a guest gets no shell and keeps the
	 * settings item in the navigation menu.
	 *
	 * @param int|null $userId the current user by default
	 */
	public static function isChatShellNavigationWebAvailableForUser(?int $userId = null): bool
	{
		return Feature::isEnabled(ChatShellNavigationWeb::class)
			&& self::isChatFoldersWebAvailableForUser($userId)
		;
	}

	public static function isVibecodeAppsFolderAvailable(): bool
	{
		return Feature::isEnabled(VibecodeAppsFolder::class);
	}

	/**
	 * Gate of the "Meetings" folder ({@see \Bitrix\Im\V2\Folder\System\MeetingFolder}). The module
	 * check is part of the gate, not of the folder: without calendar there is nothing that creates
	 * a meeting chat, so the folder would stand in the strip guaranteed empty.
	 */
	public static function isMeetingsFolderAvailable(): bool
	{
		if (!Feature::isEnabled(MeetingsFolder::class))
		{
			return false;
		}

		return Loader::includeModule('calendar');
	}

	public static function isVibecodeAppOpenButtonAvailable(): bool
	{
		return Feature::isEnabled(VibecodeAppOpenButton::class);
	}

	/**
	 * The option goes first, so the vibecodeconnector bridge is not called until the banner is rolled out.
	 *
	 * @param int|null $userId the current user by default
	 */
	public static function isVibecodeAppChatBannerAvailableForUser(?int $userId = null): bool
	{
		return self::resolveVibecodeAppChatBanner(static fn (): bool => Catalog::isAvailableForUser($userId));
	}

	private static function resolveVibecodeAppChatBanner(\Closure $hasCatalogAccess): bool
	{
		return Feature::isEnabled(VibecodeAppChatBanner::class)
			&& $hasCatalogAccess() === true
		;
	}

	public static function isCopilotWebSearchEnabledByAdmin(): bool
	{
		return ServiceLocator::getInstance()->get(WebSearchService::class)->isEnabledByAdmin();
	}

	public static function isCopilotWebSearchAllowedByTariff(): bool
	{
		return ServiceLocator::getInstance()->get(WebSearchService::class)->isAllowedByTariff();
	}

	public static function isAiAssistantRegenerateAvailable(): bool
	{
		return Feature::isEnabled(AiAssistantRegenerate::class);
	}

	/**
	 * Tells the client that aiassistant already marks its answers as eligible for a repeat,
	 * so the button can follow the message flag instead of the feature flag alone.
	 */
	public static function isAiAssistantRegenerateSignalsReady(): bool
	{
		if (!self::isAiAssistantRegenerateAvailable() || !Loader::includeModule('aiassistant'))
		{
			return false;
		}

		// The predicate ships with the aiassistant module on its own schedule: until it lands,
		// report "not ready" instead of promising the client a flag nobody writes.
		return method_exists(
			\Bitrix\AiAssistant\Core\Regeneration\Service\RegenerationService::class,
			'canRegenerate',
		);
	}

	public static function isReplyWithMediaAvailable(): bool
	{
		return Feature::isEnabled(ReplyWithMedia::class);
	}

	public static function isTaskResultButtonAvailable(): bool
	{
		return Feature::isEnabled(TaskResultButton::class);
	}

	public static function isMessageFormBlockAvailable(): bool
	{
		return Feature::isEnabled(MessageFormBlock::class);
	}

	public static function isMessageBlockFooterAvailable(): bool
	{
		return Feature::isEnabled(MessageBlockFooter::class);
	}

	public static function isMessageFileAudioBlockAvailable(): bool
	{
		return Feature::isEnabled(MessageFileAudioBlock::class);
	}

	public static function isBitrixGptScrollPinAvailable(): bool
	{
		return Feature::isEnabled(BitrixGptScrollPin::class);
	}

	public static function isCollabPreviewSourceEnabled(): bool
	{
		return Feature::isEnabled(CollabPreviewSource::class);
	}

	public static function isDiskImHeadIndexingEnabled(): bool
	{
		return Feature::isEnabled(DiskImHeadIndexing::class);
	}

	public static function isMarkdownAvailable(): bool
	{
		return Feature::isEnabled(Markdown::class);
	}

	public static function isCopilotDraftChatAvailable(): bool
	{
		return Option::get('im', 'copilot_draft_chat_available', 'N') === 'Y';
	}

	public static function isAttachChatToProjectAvailable(): bool
	{
		if (!Collab::isNewProjectsAvailable())
		{
			return false;
		}

		return Feature::isEnabled(AttachChatToProject::class);
	}

	public static function isCopilotSimpleReasoningAvailable(): bool
	{
		return Option::get('im', 'copilot_simple_reasoning_available', 'N') === 'Y';
	}

	public static function isAiTaskCreationFromMessagesAvailable(): bool
	{
		if (!CopilotChat::isActive())
		{
			return false;
		}

		if (!Feature::isEnabled(AiTaskCreationFromMessages::class))
		{
			return false;
		}

		return (new Restriction())->isAiTaskCreationActive();
	}

	public static function isAirDesignEnabled(): bool
	{
		return Feature::isEnabled(AirDesign::class);
	}

	public static function isChatBotCommandsAvailable(): bool
	{
		return Feature::isEnabled(ChatBotCommands::class);
	}

	public static function isUploadCaptionMentionsFormatAvailable(): bool
	{
		return Feature::isEnabled(UploadCaptionMentionsFormat::class);
	}

	public static function isEmojiSearchAvailable(): bool
	{
		return Feature::isEnabled(EmojiSearch::class);
	}

	public static function isBackgroundAudioPlaybackAvailable(): bool
	{
		return Feature::isEnabled(BackgroundAudioPlayback::class);
	}

	public static function isVideoNotePlaybackRateAvailable(): bool
	{
		return Feature::isEnabled(VideoNotePlaybackRate::class);
	}

	public static function isMentionInstantSearchAvailable(): bool
	{
		return Feature::isEnabled(MentionInstantSearch::class);
	}

	public static function isVideoNoteCaptionAvailable(): bool
	{
		return Feature::isEnabled(VideoNoteCaption::class);
	}

	public static function isContactCardAvailable(): bool
	{
		return Feature::isEnabled(ContactCard::class);
	}

	public static function isVoiceMessageRecordingAvailable(): bool
	{
		if (!Feature::isEnabled(VoiceMessageRecording::class))
		{
			return false;
		}

		if (IsModuleInstalled('bitrix24'))
		{
			return true;
		}

		return Option::get('im', 'voice_message_recording_available', 'N') === 'Y';
	}

	public static function isVoiceMessageServerConversionAvailable(): bool
	{
		if (!Feature::isEnabled(VoiceMessageServerConversion::class))
		{
			return false;
		}

		return Loader::includeModule('transformer');
	}

	public static function isEditMessageFilesAvailable(): bool
	{
		return Feature::isEnabled(EditMessageFiles::class);
	}

	public static function isScheduledMessagesAvailable(): bool
	{
		return Feature::isEnabled(ScheduledMessages::class);
	}

	public static function isMessageSearchAvailable(): bool
	{
		return Feature::isEnabled(MessageSearch::class);
	}

	/**
	 * The block of messages is nothing a guest can be shown: a guest sits in one chat of somebody
	 * else's portal, and the search surface is deliberately left out of the guest allowlist
	 * ({@see \Bitrix\Im\V2\Controller\Filter\AuthorizationPrefilter::getAllowedGuestControllers}), so
	 * the section would stand in his client and answer every request with an access error.
	 *
	 * @param int|null $userId the current user by default
	 */
	public static function isMessageSearchAvailableForUser(?int $userId = null): bool
	{
		// no authorized user at all comes back as `false` from Common, hence the cast
		$userId ??= (int)Common::getUserId();

		return self::isMessageSearchAvailable() && !($userId > 0 && User::getInstance($userId)->isGuest());
	}

	// server-only flag: kept out of the constructor so it never leaks into featureOptions
	public static function isMessageSearchIndexingEnabled(): bool
	{
		return Feature::isEnabled(MessageSearchIndexing::class);
	}

	public static function isChatThreadsAvailable(): bool
	{
		return Feature::isEnabled(ChatThreads::class);
	}

	/**
	 * The flag gates the interface only: server operations of the personal pin stay open, and marks
	 * already made survive the flag being lowered.
	 */
	public static function isPersonalPinAvailable(): bool
	{
		return Feature::isEnabled(PersonalPin::class);
	}

	/**
	 * Unlike the neighbouring portal-wide flags this one depends on the current user, so the whole
	 * rule stays in the policy service and is never restated here.
	 */
	public static function isVideoNoteDownloadAvailable(): bool
	{
		return Locator::getVideoNoteDownloadPolicy()->isAllowed(User::getCurrent());
	}
}
