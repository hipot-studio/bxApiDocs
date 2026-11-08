<?php

namespace Bitrix\ImMobile;

use Bitrix\Im\V2\Application\Features;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Loader;
use Bitrix\Vibecodeconnector\Public\Service\AvailabilityService;
use Bitrix\Vibecodeconnector\Public\Service\NewAppsBadgeAvailability;

class Settings
{
	public static function isBetaAvailable(): bool
	{
		return \Bitrix\Main\Config\Option::get('immobile', 'beta_available', 'N') === 'Y';
	}

	public static function isChatLocalStorageAvailable(): bool
	{
		$isChatLocalStorageAvailable = \Bitrix\Main\Config\Option::get('immobile', 'chat_local_storage_available', 'Y') === 'Y';
		if (!$isChatLocalStorageAvailable)
		{
			return false;
		}

		if (!self::isSyncServiceEnabled())
		{
			return false;
		}

		return true;
	}

	public static function isSyncServiceEnabled(): bool
	{
		if (!\Bitrix\Main\Loader::includeModule('im'))
		{
			return false;
		}

		return \Bitrix\Im\V2\Sync\SyncService::isEnable();
	}

	public static function planLimits(): ?array
	{
		if (!\Bitrix\Main\Loader::includeModule('im'))
		{
			return null;
		}

		return \Bitrix\Im\V2\TariffLimit\Limit::getInstance()->getRestrictions();
	}

	public static function getImFeatures(): ?Features
	{
		if (!\Bitrix\Main\Loader::includeModule('im'))
		{
			return null;
		}

		return Features::get();
	}

	public static function getMultipleActionMessageLimit(): ?int
	{
		if (!\Bitrix\Main\Loader::includeModule('im'))
		{
			return null;
		}

		return \Bitrix\Im\V2\Message\MessageService::getMultipleActionMessageLimit();
	}

	public static function isTasksRecentListAvailable(): bool
	{
		return Loader::includeModule('tasks');
	}


	public static function isMarketAvailable(): bool
	{
		return \Bitrix\Main\Config\Option::get('immobile', 'is_market_available', 'N') === 'Y';
	}

	public static function isVibecodeButtonAvailable(): bool
	{
		if (!Loader::includeModule('vibecodeconnector'))
		{
			return false;
		}

		if (!class_exists(AvailabilityService::class))
		{
			// TODO: remove this legacy fallback once vibecodeconnector ships the availability predicate everywhere.
			return \Bitrix\Main\Config\Option::get('immobile', 'is_vibecode_button_available', 'N') === 'Y';
		}

		$currentUserId = (int)CurrentUser::get()->getId();

		return (new AvailabilityService())->isAvailableForUser($currentUserId);
	}

	public static function isVibecodeRecentActionAvailable(): bool
	{
		return \Bitrix\Main\Config\Option::get('immobile', 'vibecode_recent_action_available', 'N') === 'Y';
	}

	/**
	 * Whether the Vibe button carries the counter of the user's new apps. The connector decides:
	 * the counter gives way to the app chats once they are on, the messenger folder announces the
	 * novelties instead. A connector without the predicate knows no app chats, so the counter stays.
	 */
	public static function isVibecodeNewAppsBadgeAvailable(): bool
	{
		if (!Loader::includeModule('vibecodeconnector'))
		{
			return false;
		}

		if (!class_exists(NewAppsBadgeAvailability::class))
		{
			return true;
		}

		return (new NewAppsBadgeAvailability())->isAvailable();
	}

	public static function isVibecodeAppChatSendPermissionAvailable(): bool
	{
		return \Bitrix\Main\Config\Option::get(
			'immobile',
			'vibecode_app_chat_send_permission_available',
			'Y',
		) === 'Y';
	}

	public static function isMarkdownNativeRendererAvailable(): bool
	{
		return \Bitrix\Main\Config\Option::get('immobile', 'is_markdown_native_renderer_available', 'N') === 'Y';
	}

	public static function isCopilotSelectModelEnabled(): bool
	{
		return true;
	}

	public static function isMarkdownParserEnabled(): bool
	{
		// Unified flag with the web messenger: im.markdown_available (see Im\V2\Application\Features)
		if (!\Bitrix\Main\Loader::includeModule('im'))
		{
			return false;
		}

		return Features::isMarkdownAvailable();
	}

	public static function isOpenlinesInMessengerV2Available(): bool
	{
		return \Bitrix\Main\Config\Option::get('immobile', 'openlines_in_messenger_v2_available', 'Y') === 'Y';
	}

	public static function isAutoTaskEnabled(): bool
	{
		if (!Loader::includeModule('im'))
		{
			return false;
		}

		return (new \Bitrix\Im\V2\Integration\AI\Restriction())->isAutoTaskActive();
	}

	public static function isAutoTaskUIAvailable(): bool
	{
		return true;
	}

	public static function isVideoNotePlaybackRateAvailable(): bool
	{
		// Unified flag with the web messenger: im.video_note_playback_rate_available (see Im\V2\Application\Features)
		if (!Loader::includeModule('im'))
		{
			return false;
		}

		// im and immobile are updated independently, so an older im may not ship the predicate yet
		if (!method_exists(Features::class, 'isVideoNotePlaybackRateAvailable'))
		{
			return false;
		}

		return Features::isVideoNotePlaybackRateAvailable();
	}
}
