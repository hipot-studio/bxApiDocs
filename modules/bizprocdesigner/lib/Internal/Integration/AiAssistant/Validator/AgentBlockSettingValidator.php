<?php

namespace Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Validator;

use Bitrix\BizprocDesigner\Internal\Entity\BlockTypeDetail;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Entity\AgentSetting;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Enum\GraphErrorCode;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Error\GraphError;
use Bitrix\Main\Result;

final class AgentBlockSettingValidator
{
	private ?AgentSetting $validSetting = null;
	private readonly AgentSettingNameValidator $nameValidator;
	private readonly AgentSettingValueValidator $valueValidator;

	public function __construct(
		?AgentSettingNameValidator $nameValidator = null,
		?AgentSettingValueValidator $valueValidator = null,
		?ValidationContext $context = null,
	)
	{
		$this->nameValidator = $nameValidator ?? new AgentSettingNameValidator();
		$this->valueValidator = $valueValidator ?? new AgentSettingValueValidator($context);
	}

	public function validate(
		mixed $setting,
		string $path,
		?BlockTypeDetail $blockTypeDetail,
		?string $blockType = null,
		?string $presetId = null,
		bool $allowPublishedExpressionKeys = false,
	): Result
	{
		$this->validSetting = null;

		if (!is_array($setting))
		{
			return (new Result())->addError(
				GraphError::at($path, "{$path} should be object", GraphErrorCode::SettingEntryInvalid),
			);
		}

		$result = new Result();
		$name = $setting['name'] ?? null;
		$value = $setting['value'] ?? null;
		$nameValidateResult = $this->nameValidator->validate($name, $value, $path, $blockTypeDetail);
		$result->addErrors($nameValidateResult->getErrors());
		$settingConfig = $this->nameValidator->getSetting();

		$valueValidateResult = $this->valueValidator->validate(
			$value,
			$path,
			$settingConfig,
			$blockType,
			$presetId,
			$allowPublishedExpressionKeys,
		);
		$result->addErrors($valueValidateResult->getErrors());

		if ($nameValidateResult->isSuccess()
			&& $valueValidateResult->isSuccess()
			&& $this->valueValidator->getExpectedTypeValue() !== null
		)
		{
			$this->validSetting = new AgentSetting($name, $this->valueValidator->getExpectedTypeValue());
		}

		return $result;
	}

	public function getValidSetting(): ?AgentSetting
	{
		return $this->validSetting;
	}
}
