<?php

declare(strict_types=1);

namespace Bitrix\Booking\Service;

use Bitrix\Bitrix24\Feature;

use Bitrix\Main\Loader;
use Bitrix\Main\Config\Option;

final class BookingFeature
{
	private const MODULE_ID = 'booking';
	private const FEATURE_ID = 'booking';
	private const TRIAL_DAYS = 30;

	public static function isOn(): bool
	{
		return self::isOptionEnabled();
	}

	public static function isFeatureEnabled(): bool
	{
		if (!Loader::includeModule('bitrix24'))
		{
			return true;
		}

		return Feature::isFeatureEnabled(self::FEATURE_ID);
	}

	public static function isFeatureEnabledByTrial(): bool
	{
		if (!Loader::includeModule('bitrix24'))
		{
			return true;
		}

		return (
			Feature::isFeatureEnabled(self::FEATURE_ID)
			&& array_key_exists(self::FEATURE_ID, Feature::getTrialFeatureList())
		);
	}

	public static function canTurnOnTrial(): bool
	{
		return (
			Loader::includeModule('bitrix24')
			&& (
				!self::isFeatureEnabled()
				&& !self::isTrialFeatureWasEnabled()
			)
		);
	}

	public static function canTurnOnDemo(): bool
	{
		return false;
	}

	public static function turnOnTrialIfPossible(): void
	{
		if (!self::canTurnOnTrial())
		{
			return;
		}

		self::turnOnTrial();
	}

	private static function turnOnTrial(): void
	{
		Feature::setFeatureTrialable(self::FEATURE_ID, [
			'days' => self::TRIAL_DAYS,
		]);
		Feature::trialFeature(self::FEATURE_ID);

		Feature::setFeatureTrialable('notifications', [
			'days' => self::TRIAL_DAYS,
		]);
		Feature::trialFeature('notifications');

		self::setTrialOption();
	}

	private static function isOptionEnabled(): bool
	{
		return true;
	}

	private static function setTrialOption(): void
	{
		Option::set(self::MODULE_ID, 'trialable_feature_enabled', true);
	}

	private static function isTrialFeatureWasEnabled(): bool
	{
		return (bool)Option::get(self::MODULE_ID, 'trialable_feature_enabled', false);
	}
}
