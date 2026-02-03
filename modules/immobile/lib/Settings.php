<?php

namespace Bitrix\ImMobile;

use Bitrix\Im\V2\Application\Features;
use Bitrix\Main\Loader;

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

	public static function shouldShowChatV2UpdateHint(): bool
	{
		return \Bitrix\Main\Config\Option::get('immobile', 'should_show_chat_m1_update_hint', 'Y') === 'Y';
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
		if (!Loader::includeModule('tasks'))
		{
			return false;
		}

		return \Bitrix\Main\Config\Option::get('im', 'is_tasks_recent_list_available', 'Y') === 'Y';
	}

	public static function isMessengerV2Enabled(): bool
	{
		return true;
	}

	public static function isMultipleReactionsEnabled(): bool
	{
		if (\Bitrix\Main\Config\Option::get('im', 'multiple_reactions_available', 'N') === 'Y')
		{
			return true;
		}

		return false;
	}

	public static function isMessengerV2EnabledForCurrentUser(): bool
	{
		return true;
	}

	public static function toggleMessengerV2ForCurrentUser(): array
	{
		return [
			'isSuccess' => true,
			'isMessengerV2Enabled' => self::isMessengerV2Enabled(),
			'isMessengerV2EnabledForCurrentUser' => self::isMessengerV2EnabledForCurrentUser(),
		];
	}

	public static function enableMessengerV2ForCurrentUser(): bool
	{
		return \CUserOptions::SetOption('immobile', 'messenger_v2_enabled', 'Y');
	}

	public static function disableMessengerV2ForCurrentUser(): bool
	{
		return \CUserOptions::SetOption('immobile', 'messenger_v2_enabled', 'N');
	}

	public static function isCopilotSelectModelEnabled(): bool
	{
		if (\Bitrix\Main\Config\Option::get('im', 'copilot_select_model_activated', 'N') === 'Y')
		{
			return true;
		}

		return false;
	}

	public static function isAiAssistantMcpSelectorAvailable(): bool
	{
		return \Bitrix\Main\Config\Option::get('immobile', 'ai_assistant_mcp_selector_available', 'N') === 'Y';
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
		return \Bitrix\Main\Config\Option::get('immobile', 'is_auto_task_ui_available', 'N') === 'Y';
	}
}
