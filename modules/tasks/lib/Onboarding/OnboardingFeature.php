<?php

declare(strict_types=1);

namespace Bitrix\Tasks\Onboarding;

use Bitrix\Main\Config\Feature;
use Bitrix\Main\Config\Feature\Context;
use Bitrix\Tasks\Feature\OnboardingFeature as OnboardingFeatureFlag;

final class OnboardingFeature
{
	public static function isAvailable(): bool
	{
		return Feature::isEnabled(OnboardingFeatureFlag::class, new Context(null));
	}

	public static function isNewPortal(): bool
	{
		return Portal::getInstance()->isNew();
	}

}
