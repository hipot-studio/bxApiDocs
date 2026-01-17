<?php

namespace Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Validator;

use Bitrix\Bizproc\Integration\AiAssistant\ActivityAiPropertyConverter;
use Bitrix\Bizproc\Internal\Entity\Activity\Setting;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Bitrix\Main\Web\Json;

class AgentSettingValueValidator
{
	private string|array|null $expectedTypeValue = null;

	public function validate(mixed $value, string $path, ?Setting $settingConfig): Result
	{
		$this->expectedTypeValue = null;
		if (!is_string($value) && !is_array($value))
		{
			return (new Result())->addError(new Error("{$path}.value is required"));
		}

		if ($settingConfig === null)
		{
			return new Result();
		}

		if ($settingConfig->type === ActivityAiPropertyConverter::SETTING_TYPE_MAP)
		{
			if (is_string($value))
			{
				$value = $this->getValueAsArray($value);
			}

			if (!is_array($value))
			{
				return (new Result())->addError(new Error("{$path}.value expected type is object"));
			}
			$this->expectedTypeValue = $value;

			return new Result();
		}

		if (!$settingConfig->multiple && !is_string($value))
		{
			return (new Result())->addError(new Error("{$path}.value expected type is string"));
		}

		if ($settingConfig->required && ($value === '' || (is_array($value) && count($value) === 0)))
		{
			return (new Result())->addError(new Error("{$path}.value should not be empty"));
		}

		$this->expectedTypeValue = $value;

		return new Result();
	}

	private function getValueAsArray(string|array $value): mixed
	{
		if (is_array($value))
		{
			return $value;
		}

		try
		{
			return Json::decode($value);
		}
		catch (ArgumentException)
		{
			return null;
		}
	}

	public function getExpectedTypeValue(): array|string|null
	{
		return $this->expectedTypeValue;
	}
}