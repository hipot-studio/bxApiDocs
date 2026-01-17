<?php

declare(strict_types=1);

namespace Bitrix\Booking\Service;

use Bitrix\Bitrix24\Feature;
use Bitrix\Main\Loader;
use Bitrix\Main\Config\Option;

final class BookingFeature
{
	public const FEATURE_ID_BOOKING = 'booking';
	public const FEATURE_ID_CALENDAR_INTEGRATION = 'booking_calendar';
	public const FEATURE_ID_WAIT_LIST = 'booking_waitlist';
	public const FEATURE_ID_OVERBOOKING = 'booking_overbooking';
	public const FEATURE_ID_MULTI_RESOURCE_BOOKING = 'booking_multi';
	public const FEATURE_ID_CRM_CREATE_BOOKING = 'booking_crm_slider';
	public const FEATURE_ID_NOTIFICATIONS_SETTINGS = 'booking_notifications_settings';

	private static array $features = [
		self::FEATURE_ID_BOOKING,
		self::FEATURE_ID_CALENDAR_INTEGRATION,
		self::FEATURE_ID_WAIT_LIST,
		self::FEATURE_ID_OVERBOOKING,
		self::FEATURE_ID_MULTI_RESOURCE_BOOKING,
		self::FEATURE_ID_CRM_CREATE_BOOKING,
		self::FEATURE_ID_NOTIFICATIONS_SETTINGS,
	];

	private const TRIAL_DAYS = 30;
	private const MODULE_ID = 'booking';

	public static function getFeatures(): array
	{
		$result = [];

		foreach (self::$features as $featureId)
		{
			$result[] = [
				'id' => $featureId,
				'isEnabled' => self::isFeatureEnabled($featureId),
			];
		}

		return $result;
	}

	public static function isFeatureEnabled(string $featureId = self::FEATURE_ID_BOOKING): bool
	{
		if (!Loader::includeModule('bitrix24'))
		{
			return true;
		}

		return Feature::isFeatureEnabled($featureId);
	}

	public static function areFeaturesEnabled(array $featureIds = []): bool
	{
		foreach ($featureIds as $featureId)
		{
			if (!self::isFeatureEnabled($featureId))
			{
				return false;
			}
		}

		return true;
	}

	public static function canTurnOnTrial(): bool
	{
		return (
			Loader::includeModule('bitrix24')
			&& (
				!self::isFeatureEnabled(self::FEATURE_ID_BOOKING)
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
		foreach (self::$features as $featureId)
		{
			self::trialFeature($featureId);
		}

		self::setTrialOption();
	}

	private static function trialFeature(string $featureId): void
	{
		Feature::setFeatureTrialable($featureId, [
			'days' => self::TRIAL_DAYS,
		]);
		Feature::trialFeature($featureId);
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
