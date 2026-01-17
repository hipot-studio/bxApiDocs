<?php

namespace Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Validator;

use Bitrix\BizprocDesigner\Internal\Entity\BlockTypeDetail;
use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Bitrix\Bizproc\Internal\Entity\Activity\Setting;

class AgentSettingNameValidator
{
	private ?Setting $setting = null;

	public function validate(mixed $name, string $path, ?BlockTypeDetail $blockTypeDetail): Result
	{
		$this->setting = null;
		if (!is_string($name) || $name === '')
		{
			return (new Result())->addError(new Error("{$path}.name should be not empty string"));
		}

		if ($blockTypeDetail)
		{
			$this->setting = $blockTypeDetail->settings->findFirstByName($name);
			if ($this->setting === null)
			{
				return (new Result())->addError(new Error("{$path}.name is incorrect"));
			}
		}

		return new Result();
	}

	public function getSetting(): ?Setting
	{
		return $this->setting;
	}
}