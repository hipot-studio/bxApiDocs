<?php
declare(strict_types=1);

namespace Bitrix\Im\Public\Features;

use Bitrix\Main\Config\Feature\AbstractFlag;
use Bitrix\Main\Config\Option;

final class LegacyFeatureFlagRegistry
{
	private const MODULE_ID = 'im';
	private const OPTIONS = [
		'desktop_redirect_available' => [DesktopRedirect::class, 'N'],
		'chat_sharing_link_available' => [ChatSharingLink::class, 'N'],
		'ai_assistant_mcp_selection_restore_available' => [AiAssistantMcpSelectionRestore::class, 'N'],
		'ai_assistant_mcp_multi_select_available' => [AiAssistantMcpMultiSelect::class, 'N'],
		'text_format_toolbar_available' => [TextFormatToolbar::class, 'N'],
		'counter_v3_available' => [CounterV3::class, 'N'],
		'mounted_tasks_card_available' => [MountedTasksCard::class, 'N'],
		'adding_user_by_mention_available' => [AddingUserByMention::class, 'N'],
		'mention_add_to_chat_popup_available' => [MentionAddToChatPopup::class, 'N'],
		'message_date_navigation_available' => [MessageDateNavigation::class, 'N'],
		'external_chat_message_forwarding_available' => [ExternalChatMessageForwarding::class, 'N'],
		'forward_multiple_recipients_available' => [ForwardMultipleRecipients::class, ''],
		'chat_folders_web_available' => [ChatFoldersWeb::class, 'N'],
		'chat_folder_all_available' => [ChatFolderAll::class, 'N'],
		'chat_shell_navigation_web_available' => [ChatShellNavigationWeb::class, 'N'],
		'folder_mute_available' => [FolderMute::class, 'N'],
		'folder_scoped_pins_available' => [FolderScopedPins::class, 'N'],
		'vibecode_apps_folder_available' => [VibecodeAppsFolder::class, 'N'],
		'meetings_folder_available' => [MeetingsFolder::class, 'N'],
		'vibecode_app_open_button_available' => [VibecodeAppOpenButton::class, 'N'],
		'vibecode_app_chat_banner_available' => [VibecodeAppChatBanner::class, 'N'],
		'ai_assistant_regenerate_available' => [AiAssistantRegenerate::class, 'N'],
		'reply_with_media_available' => [ReplyWithMedia::class, 'N'],
		'task_result_button_available' => [TaskResultButton::class, 'N'],
		'message_form_block_available' => [MessageFormBlock::class, 'N'],
		'message_block_footer_available' => [MessageBlockFooter::class, 'N'],
		'message_file_audio_block_available' => [MessageFileAudioBlock::class, 'N'],
		'bitrixgpt_scroll_pin_available' => [BitrixGptScrollPin::class, 'N'],
		'collab_preview_source_enabled' => [CollabPreviewSource::class, 'Y'],
		'disk_im_head_indexing_enabled' => [DiskImHeadIndexing::class, 'N'],
		'markdown_available' => [Markdown::class, 'N'],
		'copilot_dictation_available' => [CopilotDictation::class, 'N'],
		'mcp_read_tools_enabled' => [McpReadTools::class, 'Y'],
		'mcp_write_tools_enabled' => [McpWriteTools::class, 'Y'],
		'attach_chat_to_project_available' => [AttachChatToProject::class, 'Y'],
		'ai_task_creation_from_messages_available' => [AiTaskCreationFromMessages::class, 'N'],
		'ai_task_dedup_enabled' => [AiTaskDedup::class, 'N'],
		'air_design_enabled' => [AirDesign::class, 'N'],
		'chat_bot_commands_available' => [ChatBotCommands::class, 'N'],
		'upload_caption_mentions_format_available' => [UploadCaptionMentionsFormat::class, 'N'],
		'emoji_search_available' => [EmojiSearch::class, 'N'],
		'background_audio_playback_available' => [BackgroundAudioPlayback::class, 'N'],
		'video_note_playback_rate_available' => [VideoNotePlaybackRate::class, 'N'],
		'mention_instant_search_available' => [MentionInstantSearch::class, 'N'],
		'video_note_caption_available' => [VideoNoteCaption::class, 'N'],
		'contact_card_available' => [ContactCard::class, 'N'],
		'voice_message_server_conversion_available' => [VoiceMessageServerConversion::class, 'N'],
		'edit_message_files_available' => [EditMessageFiles::class, 'N'],
		'message_search_available' => [MessageSearch::class, 'N'],
		'message_search_indexing_enabled' => [MessageSearchIndexing::class, 'N'],
		'chat_threads_available' => [ChatThreads::class, 'N'],
		'personal_pin_available' => [PersonalPin::class, 'N'],
	];

	/**
	 * @return array<string, class-string<AbstractFlag>>
	 */
	public static function getOptionToFlagMap(): array
	{
		return array_map(
			static fn (array $option): string => $option[0],
			self::OPTIONS,
		);
	}

	/**
	 * @return class-string<AbstractFlag>|null
	 */
	public static function getFlagClassForOption(string $optionName): ?string
	{
		return self::OPTIONS[$optionName][0] ?? null;
	}

	public static function isLegacyOptionEnabled(string $optionName): bool
	{
		if (!isset(self::OPTIONS[$optionName]))
		{
			return false;
		}

		$defaultOptionValue = self::OPTIONS[$optionName][1];

		return Option::get(self::MODULE_ID, $optionName, $defaultOptionValue) === 'Y';
	}

	public static function isLegacyOptionEnabledForFlag(string $flagClass): bool
	{
		foreach (self::OPTIONS as $optionName => $optionData)
		{
			$registeredFlagClass = $optionData[0];

			if ($registeredFlagClass === $flagClass)
			{
				return self::isLegacyOptionEnabled($optionName);
			}
		}

		return false;
	}
}
