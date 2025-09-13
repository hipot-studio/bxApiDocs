<?php

namespace Bitrix\Im\V2\Integration\AI;

use Bitrix\AI\Engine;
use Bitrix\AI\Tuning\Defaults;
use Bitrix\AI\Tuning\Manager;
use Bitrix\AI\Tuning\Type;
use Bitrix\Imbot\Bot\CopilotChatBot;
use Bitrix\Main\Application;
use Bitrix\Main\Entity\EventResult;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;

class Restriction
{
	public const SETTING_COPILOT_CHAT_PROVIDER = 'im_chat_answer_provider';

	private const AI_TEXT_CATEGORY = 'text';
	private const DEFAULT_COPILOT_ENABLED = true;
	private const SETTING_COPILOT_CHAT = 'im_allow_chat_answer_generate';
	private const PORTAL_ZONE_BLACKLIST = ['cn'];

	private static ?bool $isActive = null;
	private static ?bool $isAvailable = null;

	public function isActive(): bool
	{
		self::$isActive ??= $this->isActiveInternal();

		return self::$isActive;
	}

	public function isAvailable(): bool
	{
		self::$isAvailable ??= $this->isAvailableInternal();

		return self::$isAvailable;
	}

	public static function onTuningLoad(): EventResult
	{
		$result = new EventResult;
		$items = [];
		$groups = [];

		if (!empty(Engine::getListAvailable(self::AI_TEXT_CATEGORY)))
		{
			$groups['im_copilot_chat'] = [
				'title' => Loc::getMessage('IM_RESTRICTION_COPILOT_GROUP_MSGVER_1'),
				'description' => Loc::getMessage('IM_RESTRICTION_COPILOT_DESCRIPTION'),
				'helpdesk' => '18505482',
			];

			$items[self::SETTING_COPILOT_CHAT] = [
				'group' => 'im_copilot_chat',
				'title' => Loc::getMessage('IM_RESTRICTION_COPILOT_TITLE'),
				'header' => Loc::getMessage('IM_RESTRICTION_COPILOT_HEADER'),
				'type' => Type::BOOLEAN,
				'default' => self::DEFAULT_COPILOT_ENABLED,
			];

			$items[self::SETTING_COPILOT_CHAT_PROVIDER] = array_merge(
				[
					'group' => 'im_copilot_chat',
					'title' => Loc::getMessage('IM_RESTRICTION_COPILOT_PROVIDER_TITLE'),
				],
				Defaults::getProviderSelectFieldParams(self::AI_TEXT_CATEGORY)
			);
		}

		$result->modifyFields([
			'items' => $items,
			'groups' => $groups,
			'itemRelations' => [
				'im_copilot_chat' => [
					self::SETTING_COPILOT_CHAT => [
						self::SETTING_COPILOT_CHAT_PROVIDER,
					],
				],
			],
		]);

		return $result;
	}

	private function isActiveInternal(): bool
	{
		if (
			!Loader::includeModule('ai')
			|| !AIHelper::getCopilotBotId()
			|| !$this->isAvailable()
		)
		{
			return false;
		}

		$engine = Engine::getListAvailable(self::AI_TEXT_CATEGORY);
		if (empty($engine))
		{
			return false;
		}

		return $this->isCopilotOptionEnabled();
	}

	private function isCopilotOptionEnabled(): bool
	{
		$settings = Manager::getTuningStorage();

		return (bool)($settings[self::SETTING_COPILOT_CHAT] ?? self::DEFAULT_COPILOT_ENABLED);
	}

	private function isAvailableInternal(): bool
	{
		// todo: need to support changes
		$portalZone = Application::getInstance()->getLicense()->getRegion() ?? 'ru';

		return !in_array($portalZone, self::PORTAL_ZONE_BLACKLIST, true);
	}
}
