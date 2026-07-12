<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\Collab\Onboarding;

use Bitrix\Main\Config\Configuration;
use Bitrix\Main\Config\Option;
use Bitrix\Socialnetwork\Collab\CollabFeature;
use Bitrix\Socialnetwork\V2\Feature;

final class OnboardingFeature
{
	public static function isOn(): bool
	{
		return Option::get('socialnetwork', 'collab_onboarding_feature', 'Y') === 'Y';
	}

	public static function turnOn(): void
	{
		Option::set('socialnetwork', 'collab_onboarding_feature', 'Y');
	}

	public static function isAvailable(): bool
	{
		// Lifecycle event handlers are intentionally disabled when Projects 2.0 is on
		// QueueAgent cleans up the queue instead
		if (Feature::isNewProjectsOn())
		{
			return false;
		}

		if (self::isDevMode())
		{
			return true;
		}

		if (!self::isOn())
		{
			return false;
		}

		return CollabFeature::isFeatureEnabled() && CollabFeature::isFeatureEnabledInPortalSettings();
	}

	private static function isDevMode(): bool
	{
		$exceptionHandling = Configuration::getValue('exception_handling');

		return !empty($exceptionHandling['debug']);
	}
}
