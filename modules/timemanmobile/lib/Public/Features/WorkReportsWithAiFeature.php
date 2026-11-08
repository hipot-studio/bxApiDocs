<?php

namespace Bitrix\TimemanMobile\Public\Features;

use Bitrix\Mobile\Config\Feature;
use Bitrix\Mobile\Config\FeatureFlag;
use Bitrix\TimemanMobile\Public\Providers\SettingsProvider;

final class WorkReportsWithAiFeature extends FeatureFlag
{
	public function isEnabled(): bool
	{
		return (new SettingsProvider())->isReportsEnabledWithAi()
			&& Feature::isDisabled(WorkReportsWithoutAiFeature::class);
	}

	// The flag is derived from portal settings and has no state of its own.
	public function enable(): void
	{}

	public function disable(): void
	{}
}
