<?php declare(strict_types=1);

namespace Bitrix\AI\Services;

use Bitrix\AI\Config;
use Bitrix\AI\Context;
use Bitrix\AI\Engine;
use Bitrix\AI\Engine\ThirdParty;
use Bitrix\AI\Facade\Bitrix24;
use Bitrix\AI\Tuning\Manager;
use Bitrix\Main\Web\Json;
use Bitrix\Bitrix24\Integration\AI\Engine as CloudEngine;

class EngineSettingsService
{
	private const TRANSCRIBE_VIDEO_CALL_TRACK_ITEM = 'transcribe_track';
	private const TRANSCRIBE_CRM_CALL_TRACK_ITEM = 'crm_copilot_fill_item_from_call_engine_audio';
	private const RESUME_TRANSCRIPTION_ITEM = 'resume_transcription';
	private const TRANSCRIBE_TRACK_ITEM = 'transcribe_track';
	private const FLOWS_ITEM = 'tasks_flows_text_generate_engine';
	private const BGPT = 'b24ai';
	private const BITRIX_AUDIO = 'BitrixAudio';
	private const BITRIX_AUDIO_CALL = 'BitrixAudioCall';
	private const VALUE_CHATGPT = 'chatgpt';
	private const VALUE_AUDIO = 'audio';
	private const VALUE_AUDIO_CALL = 'audiocall';
	private const AVAILABLE_FOR_CHATGPT = [
		'landing_text_provider',
		'landing_site_text_provider',
	];

	public function __construct()
	{
	}

	public function resetToBitrixGPTInCloud(): void
	{
		$options = Config::getValue('bitrixgpt_options');
		if (is_null($options))
		{
			return;
		}

		$decodedOptions = json_decode($options, true);
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

		$preferredEngine = Engine::getByCode(CloudEngine\Bitrix24::ENGINE_CODE, Context::getFake());
		if (is_null($preferredEngine))
		{
			return;
		}

		$preferredCode = $preferredEngine->getIEngine()->getCode();

		foreach ($itemsToForceChange as $item)
		{
			$this->updateEngineCode($manager, $preferredCode, $item);
		}

		$manager->save();

		$decodedOptions['portalSettingsItemsToForceReset'] = [];
		Config::setOptionsValue('bitrixgpt_options', Json::encode($decodedOptions));
	}

	public function resetToBitrixAudioInCloud(): void
	{
		$options = Config::getValue('bitrixaudio_portalSettingsItemsToForceReset');
		if (is_null($options))
		{
			return;
		}

		$itemsToForceChange = json_decode($options, true);
		if (empty($itemsToForceChange) || !Bitrix24::shouldUseB24())
		{
			return;
		}

		$manager = new Manager();
		$managerNeedsSaving = false;
		foreach ($itemsToForceChange as $item)
		{
			$preferredEngine = null;
			if ($item === self::TRANSCRIBE_VIDEO_CALL_TRACK_ITEM)
			{
				$preferredEngine = Engine::getByCode(CloudEngine\BitrixAudioCall::ENGINE_CODE, Context::getFake());
			}
			else
			{
				$preferredEngine = Engine::getByCode(CloudEngine\BitrixAudio::ENGINE_CODE, Context::getFake());
			}

			if (is_null($preferredEngine))
			{
				continue;
			}
			$preferredCode = $preferredEngine->getIEngine()->getCode();

			$this->updateEngineCode($manager, $preferredCode, $item);
			$managerNeedsSaving = true;
		}

		if ($managerNeedsSaving)
		{
			$manager->save();
		}

		Config::setOptionsValue('bitrixaudio_portalSettingsItemsToForceReset', json_encode([]));
	}

	public function resetResumeTranscriptionToBGPT(): void
	{
		$manager = new Manager();
		$this->updateEngineCode($manager, self::BGPT, self::RESUME_TRANSCRIPTION_ITEM);
		$this->updateEngineCode($manager, self::BITRIX_AUDIO_CALL, self::TRANSCRIBE_TRACK_ITEM);
		$manager->save();
	}

	public function resetFlowsToBGPT(): void
	{
		$manager = new Manager();
		$this->updateEngineCode($manager, self::BGPT, self::FLOWS_ITEM);
		$manager->save();
	}

	public function enforceEngineBaseline(): void
	{
		$manager = new Manager();
		$managerNeedsSaving = false;

		foreach ($manager->getList() as $group)
		{
			foreach ($group->getItems() as $item)
			{
				$value = $item->getValue();
				if (!is_scalar($value))
				{
					continue;
				}

				$code = $item->getCode();
				$normalized = mb_strtolower((string)$value);

				switch ($normalized)
				{
					case self::VALUE_CHATGPT:
						if (!in_array($code, self::AVAILABLE_FOR_CHATGPT, true))
						{
							$this->updateEngineCode($manager, self::BGPT, $code);
							$managerNeedsSaving = true;
						}
						break;

					case self::VALUE_AUDIO:
						$this->updateEngineCode($manager, self::BITRIX_AUDIO, $code);
						$managerNeedsSaving = true;
						break;

					case self::VALUE_AUDIO_CALL:
						$this->updateEngineCode($manager, self::BITRIX_AUDIO_CALL, $code);
						$managerNeedsSaving = true;
						break;
				}
			}
		}

		if ($managerNeedsSaving)
		{
			$manager->save();
		}
	}

	private function updateEngineCode(Manager $manager, string $preferredCode, string $itemName): void
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
			&& !($engine->getIEngine() instanceof ThirdParty)
		)
		{
			$item->setValue($preferredCode);
		}
	}
}
