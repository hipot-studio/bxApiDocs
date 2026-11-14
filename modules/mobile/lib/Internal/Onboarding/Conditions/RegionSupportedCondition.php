<?php

namespace Bitrix\Mobile\Internal\Onboarding\Conditions;

use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Bitrix\Mobile\Internal\Onboarding\Region\RegionConfig;

class RegionSupportedCondition implements PushSendCondition
{
	public const ERROR_NOT_SUPPORTED = 'Onboarding push feature is disabled';
	public const CODE_NOT_SUPPORTED = 'region_not_supported';

	public function check(RegionConfig $config): Result
	{
		$result = new Result();

		if (!$config->isSupported())
		{
			$result->addError(new Error(self::ERROR_NOT_SUPPORTED, self::CODE_NOT_SUPPORTED));
		}

		return $result;
	}
}
