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
		return Loader::includeModule('tasks');
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
