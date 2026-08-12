<?php

namespace Bitrix\Socialnetwork\Helper;

use Bitrix\Bitrix24;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Loader;
use Bitrix\Socialnetwork\Collab\CollabFeature;
use Bitrix\Socialnetwork\Collab\Registry\CollabRegistry;
use Bitrix\Tasks\Flow\FlowFeature;

class Feature
{
	const PROJECTS_GROUPS = 'socialnetwork_projects_groups';
	const SCRUM_CREATE = 'socialnetwork_scrum_create';
	const PROJECTS_ACCESS_PERMISSIONS = 'socialnetwork_projects_access_permissions';
	const PROJECTS_COPY = 'socialnetwork_copy_project';

	public const PROJECTS_TRIAL_DAYS = 15;

	const FIRST_ERA = 'socialnetwork_first_era';

	public static function isFeatureEnabled(string $featureName, int $groupId = 0): bool
	{
		if (!Loader::includeModule('bitrix24'))
		{
			return true;
		}

		if (
			$featureName === self::PROJECTS_GROUPS
			&& Loader::includeModule('tasks')
			&& FlowFeature::isFeatureEnabled()
		)
		{
			return true;
		}

		if ($groupId && !\Bitrix\Socialnetwork\V2\Feature::isNewProjectsOn())
		{
			$isCollab = (CollabRegistry::getInstance()->get($groupId) !== null);
			if ($isCollab && CollabFeature::isFeatureEnabled())
			{
				return true;
			}
		}

		return Bitrix24\Feature::isFeatureEnabled($featureName);
	}

	public static function isFeatureEnabledByTrial(string $featureName): bool
	{
		if (!Loader::includeModule('bitrix24'))
		{
			return true;
		}

		return (
			Bitrix24\Feature::isFeatureEnabled($featureName)
			&& array_key_exists($featureName, Bitrix24\Feature::getTrialFeatureList())
		);
	}

	/**
	 * @return array{startTs: int, tillTs: int}|null
	 */
	public static function getTrialInfo(string $featureName): ?array
	{
		if (!Loader::includeModule('bitrix24'))
		{
			return null;
		}

		$trialInfo = Bitrix24\Feature::getTrialFeatureInfo($featureName);
		if ($trialInfo === null)
		{
			return null;
		}

		$startTs = strtotime((string)($trialInfo['startDate'] ?? ''));
		$tillTs = strtotime((string)($trialInfo['tillDate'] ?? ''));
		if ($startTs === false || $tillTs === false)
		{
			return null;
		}

		return [
			'startTs' => $startTs,
			'tillTs' => $tillTs,
		];
	}

	public static function isFeaturePromo(string $featureName): bool
	{
		return (
			Loader::includeModule('bitrix24')
			&& Bitrix24\Feature::isPromoEditionAvailableByFeature($featureName)
		);
	}

	public static function canTurnOnTrial(string $featureName): bool
	{
		if (self::isFeatureEnabled($featureName))
		{
			return false;
		}

		if ($featureName === self::PROJECTS_GROUPS)
		{
			return !self::isDemoFeatureWasEnabled($featureName);
		}

		if (self::isFeatureEnabled(self::FIRST_ERA))
		{
			return false;
		}

		return !self::isDemoFeatureWasEnabled($featureName);
	}

	public static function turnOnTrial($featureName, int $trialDays = self::PROJECTS_TRIAL_DAYS): void
	{
		Bitrix24\Feature::setFeatureTrialable($featureName, [
			'days' => $trialDays,
		]);

		Bitrix24\Feature::trialFeature($featureName);

		self::setDemoOption($featureName);
	}

	private static function setDemoOption(string $featureName): void
	{
		Option::set('socialnetwork', $featureName . '_trialable', true);
	}

	private static function isDemoFeatureWasEnabled(string $featureName): bool
	{
		return (bool) Option::get('socialnetwork', $featureName . '_trialable', false);
	}
}
