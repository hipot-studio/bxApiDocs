<?php

declare(strict_types=1);

namespace Bitrix\Mobile\BitrixGpt\Voice\ActionFilter;

use Bitrix\Main\Config\Feature;
use Bitrix\Main\Engine\ActionFilter\Base;
use Bitrix\Main\Error;
use Bitrix\Main\Event;
use Bitrix\Main\EventResult;
use Bitrix\Mobile\Public\Features\BitrixGptVoiceFeature;

final class FeatureFilter extends Base
{
	public function onBeforeAction(Event $event): ?EventResult
	{
		if (Feature::isEnabled(BitrixGptVoiceFeature::class))
		{
			return null;
		}

		$this->addError(new Error('Voice widget is disabled.', 'FEATURE_DISABLED'));

		return new EventResult(EventResult::ERROR, null, null, $this);
	}
}
