<?php

namespace Bitrix\Im\V2\Integration\AI;

use Bitrix\AI\Engine;
use Bitrix\AI\Quality;
use Bitrix\AI\Tuning\Defaults;
use Bitrix\AI\Tuning\Manager;
use Bitrix\Im\V2\Integration\AI\Restriction\Type;
use Bitrix\Main\Application;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\ORM\EventResult;

class Restriction
{
	public const SETTING_COPILOT_CHAT_PROVIDER = 'im_chat_answer_provider';
	public const SETTING_TRANSCRIPTION_PROVIDER = 'im_file_transcription_provider';

	private const AI_TEXT_CATEGORY = 'text'; /** @see Engine::CATEGORIES */
	private const AI_AUDIO_CATEGORY = 'audio'; /** @see Engine::CATEGORIES */
	private const DEFAULT_COPILOT_ENABLED = true;
	private const DEFAULT_TRANSCRIPTION_ENABLED = true;
	private const DEFAULT_TRANSCRIPTION_EMOTIONS_ENABLED = true;
	private const DEFAULT_AUTO_TASK_CREATION_ENABLED = true;
	private const SETTING_COPILOT_CHAT = 'im_allow_chat_answer_generate';
	private const SETTING_TRANSCRIPTION = 'im_allow_file_transcription_generate';
	private const SETTING_AUTO_TASK_CREATION = 'im_allow_auto_task_creation_after_transcription';
	private const SETTING_TRANSCRIPTION_EMOTIONS = 'im_allow_file_transcription_emotions_generate';
	private const PORTAL_ZONE_BLACKLIST = ['cn'];
	private const TRANSCRIPTION_QUALITY = 'transcribe_chat_voice_messages'; /** @see Quality::QUALITIES */

	private static ?bool $isCopilotActive = null;
	private static ?bool $isTranscriptionActive = null;
	private static ?bool $isTranscriptionEmotionsActive = null;
	private static ?bool $isAutoTaskActive = null;
	private static ?bool $isAvailable = null;

	public function isCopilotActive(): bool
	{
		self::$isCopilotActive ??= $this->isActiveInternal(Type::Copilot);

		return self::$isCopilotActive;
	}

	public function isTranscriptionActive(): bool
	{
		self::$isTranscriptionActive ??= $this->isActiveInternal(Type::Transcription);

		return self::$isTranscriptionActive;
	}

	public function isAutoTaskActive(): bool
	{
		self::$isAutoTaskActive ??= $this->isActiveInternal(Type::AutoTask);

		return self::$isAutoTaskActive;
	}

	public function isTranscriptionEmotionsActive(): bool
	{
		self::$isTranscriptionEmotionsActive ??= $this->isActiveInternal(Type::TranscriptionEmotions);

		return self::$isTranscriptionEmotionsActive;
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
		$itemRelations = [];

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
				'type' => \Bitrix\AI\Tuning\Type::BOOLEAN,
				'default' => self::DEFAULT_COPILOT_ENABLED,
				'sort' => 100,
			];

			$items[self::SETTING_COPILOT_CHAT_PROVIDER] = array_merge(
				[
					'group' => 'im_copilot_chat',
					'title' => Loc::getMessage('IM_RESTRICTION_COPILOT_PROVIDER_TITLE'),
					'sort' => 120,
				],
				Defaults::getProviderSelectFieldParams(self::AI_TEXT_CATEGORY)
			);

			$itemRelations[self::SETTING_COPILOT_CHAT] = [self::SETTING_COPILOT_CHAT_PROVIDER];

			if (Option::get('im', 'file_transcription_available', 'N') === 'Y')
			{
				$items[self::SETTING_TRANSCRIPTION] = [
					'group' => 'im_copilot_chat',
					'title' => Loc::getMessage('IM_RESTRICTION_TRANSCRIPTION_TITLE'),
					'header' => Loc::getMessage('IM_RESTRICTION_TRANSCRIPTION_HEADER'),
					'type' => \Bitrix\AI\Tuning\Type::BOOLEAN,
					'default' => self::DEFAULT_TRANSCRIPTION_ENABLED,
					'sort' => 140,
				];

				$items[self::SETTING_TRANSCRIPTION_PROVIDER] = array_merge(
					[
						'group' => 'im_copilot_chat',
						'title' => Loc::getMessage('IM_RESTRICTION_TRANSCRIPTION_PROVIDER_TITLE'),
						'sort' => 160,
					],
					Defaults::getProviderSelectFieldParams(
						self::AI_AUDIO_CATEGORY,
						new Quality(self::TRANSCRIPTION_QUALITY)
					)
				);

				$itemRelations[self::SETTING_TRANSCRIPTION] = [self::SETTING_TRANSCRIPTION_PROVIDER];

				if (Option::get('im', 'transcription_emotions_available', 'N') === 'Y')
				{
					$items[self::SETTING_TRANSCRIPTION_EMOTIONS] = [
						'group' => 'im_copilot_chat',
						'title' => Loc::getMessage('IM_RESTRICTION_TRANSCRIPTION_EMOTIONS_TITLE'),
						'header' => Loc::getMessage('IM_RESTRICTION_TRANSCRIPTION_EMOTIONS_HEADER'),
						'type' => \Bitrix\AI\Tuning\Type::BOOLEAN,
						'default' => self::DEFAULT_TRANSCRIPTION_EMOTIONS_ENABLED,
						'sort' => 180,
					];

					$itemRelations[self::SETTING_TRANSCRIPTION][] = self::SETTING_TRANSCRIPTION_EMOTIONS;
				}

				if (Option::get('im', 'ai_task_creation_available', 'N') === 'Y')
				{
					$items[self::SETTING_AUTO_TASK_CREATION] = [
						'group' => 'im_copilot_chat',
						'title' => Loc::getMessage('IM_RESTRICTION_AUTO_TASK_CREATION_TITLE'),
						'header' => Loc::getMessage('IM_RESTRICTION_AUTO_TASK_CREATION_HEADER'),
						'type' => \Bitrix\AI\Tuning\Type::BOOLEAN,
						'default' => self::DEFAULT_AUTO_TASK_CREATION_ENABLED,
						'sort' => 200,
					];

					$itemRelations[self::SETTING_TRANSCRIPTION][] = self::SETTING_AUTO_TASK_CREATION;
				}
			}
		}

		$result->modifyFields([
			'items' => $items,
			'groups' => $groups,
			'itemRelations' => [
				'im_copilot_chat' => $itemRelations,
			],
		]);

		return $result;
	}

	private function isActiveInternal(Type $type): bool
	{
		if (
			!Loader::includeModule('ai')
			|| !$this->isAvailable()
		)
		{
			return false;
		}

		$aiCategory = match ($type)
		{
			Type::Copilot, Type::AutoTask => self::AI_TEXT_CATEGORY,
			Type::Transcription, Type::TranscriptionEmotions => self::AI_AUDIO_CATEGORY,
		};

		$engine = Engine::getListAvailable($aiCategory);
		if (empty($engine))
		{
			return false;
		}

		return match ($type)
		{
			Type::Copilot => $this->isCopilotOptionEnabled(),
			Type::Transcription => $this->isTranscriptionOptionEnabled(),
			Type::AutoTask => $this->isAiAutoTaskCreationOptionEnabled(),
			Type::TranscriptionEmotions => $this->isTranscriptionEmotionsOptionEnabled(),
		};
	}

	private function isCopilotOptionEnabled(): bool
	{
		if (!AIHelper::getCopilotBotId())
		{
			return false;
		}

		$settings = Manager::getTuningStorage();

		return (bool)($settings[self::SETTING_COPILOT_CHAT] ?? self::DEFAULT_COPILOT_ENABLED);
	}

	private function isTranscriptionOptionEnabled(): bool
	{
		$settings = Manager::getTuningStorage();

		return (bool)($settings[self::SETTING_TRANSCRIPTION] ?? self::DEFAULT_TRANSCRIPTION_ENABLED);
	}

	private function isTranscriptionEmotionsOptionEnabled(): bool
	{
		if (!$this->isTranscriptionActive())
		{
			return false;
		}

		$settings = Manager::getTuningStorage();

		return (bool)($settings[self::SETTING_TRANSCRIPTION_EMOTIONS] ?? self::DEFAULT_TRANSCRIPTION_EMOTIONS_ENABLED);
	}

	private function isAiAutoTaskCreationOptionEnabled(): bool
	{
		if (
			Option::get('im', 'ai_task_creation_available', 'N') !== 'Y'
			|| !$this->isTranscriptionActive()
		)
		{
			return false;
		}

		$settings = Manager::getTuningStorage();

		return (bool)($settings[self::SETTING_AUTO_TASK_CREATION] ?? self::DEFAULT_AUTO_TASK_CREATION_ENABLED);
	}

	private function isAvailableInternal(): bool
	{
		// todo: need to support changes
		$portalZone = Application::getInstance()->getLicense()->getRegion() ?? 'ru';

		return !in_array($portalZone, self::PORTAL_ZONE_BLACKLIST, true);
	}
}
