<?php

namespace Bitrix\UI\FeaturePromoter;

use Bitrix\Main\Config\Option;
use Bitrix\Main\Loader;

class PopupProviderAvailabilityChecker
{
	public function isAvailable(): bool
	{
		if (!Loader::includeModule('bitrix24'))
		{
			return false;
		}

		return true;
	}
}