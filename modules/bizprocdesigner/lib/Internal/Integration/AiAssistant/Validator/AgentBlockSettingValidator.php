<?php

namespace Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Validator;

use Bitrix\BizprocDesigner\Internal\Entity\BlockTypeDetail;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Entity\AgentSetting;
use Bitrix\Main\Error;
use Bitrix\Main\Result;

class AgentBlockSettingValidator
{
	private ?AgentSetting $validSetting = null;
	private readonly AgentSettingNameValidator $nameValidator;
	private readonly AgentSettingValueValidator $valueValidator;

	public function __construct(
		?AgentSettingNameValidator $nameValidator = null,
		?AgentSettingValueValidator $valueValidator = null,
	)
	{
		$this->nameValidator = $nameValidator ?? new AgentSettingNameValidator();
		$this->valueValidator = $valueValidator ?? new AgentSettingValueValidator();
	}

	public function validate(mixed $setting, string $path, ?BlockTypeDetail $blockTypeDetail): Result
	{
		$this->validSetting = null;
		if (!is_array($setting))
		{
			return (new Result())->addError(new Error("{$path} should be object"));
		}

		$result = new Result();
		$name = $setting['name'] ?? null;
		$nameValidateResult = $this->nameValidator->validate($name, $path, $blockTypeDetail);
		$result->addErrors($nameValidateResult->getErrors());
		$settingConfig = $this->nameValidator->getSetting();

		$value = $setting['value'] ?? null;
		$valueValidateResult = $this->valueValidator->validate($value, $path, $settingConfig);
		$result->addErrors($valueValidateResult->getErrors());

		if ($nameValidateResult->isSuccess()
			&& $valueValidateResult->isSuccess()
			&& $this->valueValidator->getExpectedTypeValue() !== null
		)
		{
			$this->validSetting = new AgentSetting(
				name: $name,
				value: $this->valueValidator->getExpectedTypeValue(),
			);
		}

		return $result;
	}

	public function getValidSetting(): ?AgentSetting
	{
		return $this->validSetting;
	}
}