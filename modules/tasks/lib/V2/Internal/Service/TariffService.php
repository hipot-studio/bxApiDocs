<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service;

use Bitrix\Main\Loader;
use Bitrix\Tasks\Integration\Bitrix24;
use Bitrix\Tasks\Util\Restriction\Bitrix24Restriction\Limit\ProjectLimit;
use CBitrix24;

class TariffService
{
	public function isDemo(): bool
	{
		return Loader::includeModule('bitrix24') && CBitrix24::IsDemoLicense();
	}

	public function getMailUserFeature(): string
	{
		return Bitrix24\FeatureDictionary::TASK_MAIL_USER_INTEGRATION;
	}

	public function isStakeholderAvailable(): bool
	{
		return Bitrix24::checkFeatureEnabled(Bitrix24\FeatureDictionary::TASK_OBSERVERS_PARTICIPANTS);
	}

	public function isMailUserAvailable(): bool
	{
		return Bitrix24::checkFeatureEnabled(Bitrix24\FeatureDictionary::TASK_MAIL_USER_INTEGRATION);
	}

	public function isProjectAvailable(int $groupId = 0): bool
	{
		return ProjectLimit::isFeatureEnabled($groupId);
	}

	public function getProjectFeatureId(): string
	{
		return ProjectLimit::getFeatureId();
	}

	public function isEnabled(string $featureName): bool
	{
		return Bitrix24::checkFeatureEnabled($featureName);
	}
}
