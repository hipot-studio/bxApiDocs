<?php
declare(strict_types=1);

namespace Bitrix\AI\Agents;

use Bitrix\AI\Config;
use Bitrix\AI\Context;
use Bitrix\AI\Engine;
use Bitrix\Ai\Engine\ThirdParty;
use Bitrix\AI\Facade\Bitrix24;
use Bitrix\AI\Facade\User;
use Bitrix\AI\Tuning\Manager;
use Bitrix\Im\V2\Integration\AI\Restriction;
use Bitrix\Main\Web\Json;
use Bitrix\Bitrix24\Integration\AI\Engine as CloudEngine;

/**
 * Class EngineSettings
 */
final class EngineSettings
{
	/**
	 * Resets the user's choice of the engine for text operations.
	 *
	 * This method is used in the agent.
	 * @return string
	 */
	public static function resetUserEngineChoiceAgent(): string
	{
		User::clearLastUsedEngineCodeForAll('text');

		return '';
	}

	/**
	 * Resets the user's choice of the engine for text operations when the Bitrix GPT engine is used.
	 *
	 * This method is used in the agent.
	 * @return string
	 */
	public static function resetUserEngineChoiceWhenBitrixGPTAgent(): string
	{
		$engine = Engine::getByCode(Engine\Cloud\Bitrix24::ENGINE_CODE, Context::getFake());
		if ($engine)
		{
			User::clearLastUsedEngineCodeForAll('text');
		}

		return '';
	}

	public static function resetToBitrixAgent(): string
	{
		self::resetToBitrix();

		return '';
	}

	public static function resetToBitrix(): void
	{
		$preferredEngine = Engine::getByCode(Engine\Cloud\Bitrix24::ENGINE_CODE, Context::getFake());
		if (!isset($preferredEngine))
		{
			return;
		}

		$preferredCode = $preferredEngine->getIEngine()->getCode();

		$manager = new Manager();
		/** @see Restriction::SETTING_COPILOT_CHAT_PROVIDER */
		$item = $manager->getItem('im_chat_answer_provider');
		if ($item && $item->getValue() !== $preferredCode)
		{
			$item->setValue($preferredCode);
		}

		/** @see \Bitrix\Crm\Integration\AI\EventHandler::SETTINGS_FILL_ITEM_FROM_CALL_ENGINE_TEXT_CODE */
		$item = $manager->getItem('crm_copilot_fill_item_from_call_engine_text');
		if ($item && $item->getValue() !== $preferredCode)
		{
			$item->setValue($preferredCode);
		}

		$item = $manager->getItem(Engine::getConfigCode(Engine::CATEGORIES['text']));
		if ($item && $item->getValue() !== $preferredCode)
		{
			$item->setValue($preferredCode);
		}

		/** @see \Bitrix\Crm\Integration\AI\EventHandler::SETTINGS_FILL_ITEM_FROM_CALL_ENGINE_AUDIO_CODE */
		$item = $manager->getItem('crm_copilot_fill_item_from_call_engine_audio');
		if ($item && $item->getValue() !== Engine\Cloud\BitrixAudio::ENGINE_CODE)
		{
			$item->setValue(Engine\Cloud\BitrixAudio::ENGINE_CODE);
		}

		self::resetUserEngineChoiceWhenBitrixGPTAgent();

		$manager->save();
	}

	public static function resetToBitrixGPTInCloudAgent(): string
	{
		self::resetToBitrixGPTInCloud();

		return '\Bitrix\AI\Agents\EngineSettings::resetToBitrixGPTInCloudAgent();';
	}

	public static function resetToBitrixGPTInCloud(): void
	{
		$options = Config::getValue('bitrixgpt_options');
		if (is_null($options))
		{
			return;
		}

		$decodedOptions = Json::decode($options);
		$itemsToForceChange = $decodedOptions['portalSettingsItemsToForceReset'];
		if (
			empty($itemsToForceChange)
			|| Config::getValue('bitrixgpt_enabled') !== 'Y'
			|| !Bitrix24::shouldUseB24()
		)
		{
			return;
		}

		$manager = new Manager();

		$preferredEngine = self::getEngine(CloudEngine\Bitrix24::ENGINE_CODE);
		if (is_null($preferredEngine))
		{
			return;
		}

		$preferredCode = $preferredEngine->getIEngine()->getCode();

		foreach ($itemsToForceChange as $item)
		{
			self::updateEngineCode($manager, $preferredCode, $item);
		}

		$manager->save();

		$decodedOptions['portalSettingsItemsToForceReset'] = [];
		Config::setOptionsValue('bitrixgpt_options', Json::encode($decodedOptions));
	}

	private static function getEngine(string $engineCode): ?Engine
	{
		return Engine::getByCode($engineCode, Context::getFake());
	}

	private static function updateEngineCode(Manager $manager, string $preferredCode, string $itemName): void
	{
		$item = $manager->getItem($itemName);
		if (is_null($item))
		{
			return;
		}

		$engineCode = $item->getValue();
		$engine = Engine::getByCode($engineCode, Context::getFake());
		if (is_null($engine))
		{
			return;
		}

		if (
			$engineCode !== $preferredCode
			&& !self::isThirdPartyEngine($engine->getIEngine())
		)
		{
			$item->setValue($preferredCode);
		}
	}

	private static function isThirdPartyEngine(Engine\IEngine $engine): bool
	{
		return $engine instanceof ThirdParty;
	}
}
