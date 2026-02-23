<?php

namespace Bitrix\Bizproc\Internal\Service\Feature;

use Bitrix\Bitrix24\Feature;
use Bitrix\Main\Error;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;

abstract class BaseFeature
{
	private const DEFAULT_TARIFF_ERROR_MESSAGE_KEY = 'UNAVAILABLE_BY_TARIFF_ERROR';

	abstract public function getFeatureName(): string;

	abstract public function getErrorCode(): string;

	abstract public function getTariffSliderCode(): string;

	public function isAvailable(): bool
	{
		if (!Loader::includeModule('bitrix24'))
		{
			return true;
		}

		return Feature::isFeatureEnabled($this->getFeatureName());
	}

	public function makeUnavailableByTariffError(): Error
	{
		return new Error(
			message: $this->getErrorMessage(),
			code: $this->getErrorCode(),
			customData: [
				'message' => $this->getErrorMessage(),
				'featureName' => $this->getFeatureName(),
				'tariffSliderCode' => $this->getTariffSliderCode(),
			],
		);
	}

	protected function getErrorMessage(): string
	{
		return Loc::getMessage($this->getErrorMessageKey()) ?? '';
	}

	protected function getErrorMessageKey(): string
	{
		return self::DEFAULT_TARIFF_ERROR_MESSAGE_KEY;
	}
}
