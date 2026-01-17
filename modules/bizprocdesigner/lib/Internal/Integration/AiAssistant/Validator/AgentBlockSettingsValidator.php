<?php

namespace Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Validator;

use Bitrix\BizprocDesigner\Internal\Entity\BlockTypeDetail;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Entity\AgentSettingCollection;
use Bitrix\Main\Error;
use Bitrix\Main\Result;

class AgentBlockSettingsValidator
{
	private readonly AgentBlockSettingValidator $settingValidator;
	private ?AgentSettingCollection $validSettings = null;

	public function __construct(
		?AgentBlockSettingValidator $settingValidator = null,
	)
	{
		$this->settingValidator = $settingValidator ?? new AgentBlockSettingValidator();
	}

	public function validate(mixed $settings, string $path, ?BlockTypeDetail $blockTypeDetail): Result
	{
		$this->validSettings = null;
		if (!is_array($settings))
		{
			return (new Result())->addError(new Error("{$path} should be array"));
		}
		elseif (empty($settings) && $blockTypeDetail && $blockTypeDetail->settings->count() > 0)
		{
			return (new Result())->addError(new Error("{$path} should be not empty array"));
		}

		if ($blockTypeDetail)
		{
			$this->validSettings = new AgentSettingCollection();
		}
		$result = new Result();
		foreach ($settings as $key => $setting)
		{
			$settingValidateResult = $this->settingValidator->validate(
				setting: $setting,
				path: "$path.$key",
				blockTypeDetail: $blockTypeDetail,
			);
			$result->addErrors($settingValidateResult->getErrors());
			if ($settingValidateResult->isSuccess() && $this->validSettings && $this->settingValidator->getValidSetting())
			{
				$this->validSettings->add($this->settingValidator->getValidSetting());
			}
		}

		return $result;
	}

	public function getValidSettings(): ?AgentSettingCollection
	{
		return $this->validSettings;
	}

}