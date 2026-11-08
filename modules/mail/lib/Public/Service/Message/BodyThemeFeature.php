<?php

declare(strict_types=1);

namespace Bitrix\Mail\Public\Service\Message;

use Bitrix\Mail\Public\Features\MobileMessageDarkThemeFeature;
use Bitrix\Main\Config\Feature;

/**
 * Whether the mobile clients may repaint message bodies for a dark theme. A consumer asks this
 * before it adapts a body, instead of reading the feature flag of the mail module.
 */
final class BodyThemeFeature
{
	public static function isMobileAvailable(): bool
	{
		return Feature::isEnabled(MobileMessageDarkThemeFeature::class);
	}
}
