<?php

namespace Bitrix\Tasks\V2\Infrastructure\Rest\Provider;

use Bitrix\Tasks\V2\FormV2Feature;

class CheckRestV3IsEnabledProvider implements \Bitrix\Rest\V3\Schema\CheckEnabledProvider
{
	public function isEnabled(): bool
	{
		return FormV2Feature::isOn();
	}
}