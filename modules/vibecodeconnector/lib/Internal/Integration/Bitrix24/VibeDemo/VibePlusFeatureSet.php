<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Integration\Bitrix24\VibeDemo;

use Bitrix\Bitrix24\Feature;

final class VibePlusFeatureSet
{
	public const FEATURE_IDS = [
		'rest_access',
		'vibecode',
		'mcp',
		'ai_monthly_pool_by_version',
	];

	public static function getDisabledFeatureIds(): array
	{
		$featureIds = [];
		foreach (self::FEATURE_IDS as $featureId)
		{
			if (!Feature::isFeatureEnabled($featureId))
			{
				$featureIds[] = $featureId;
			}
		}

		return $featureIds;
	}

	public static function hasFullAccess(): bool
	{
		return self::getDisabledFeatureIds() === [];
	}

	public static function getTrialExpireDates(): array
	{
		$dates = [];
		foreach (self::FEATURE_IDS as $featureId)
		{
			$dates[$featureId] = Feature::getTrialFeatureInfo($featureId)['tillDate'] ?? null;
		}

		return $dates;
	}
}
