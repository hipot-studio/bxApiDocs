<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service;

use Bitrix\Main\Loader;
use Bitrix\Tasks\Integration\Bitrix24;
use Bitrix\Tasks\Internals\Task\ProjectDependenceTable;
use Bitrix\Tasks\Util\Restriction\Bitrix24Restriction\Limit;
use Bitrix\Tasks\Util\Restriction\Bitrix24Restriction\Limit\ProjectLimit;
use Bitrix\Tasks\Util\Restriction\Bitrix24Restriction\Limit\TaskLimit;
use Bitrix\Tasks\V2\Internal\DI\Container;
use Bitrix\Tasks\V2\Internal\Repository\TariffRestrictionRepositoryInterface;
use CBitrix24;

class TariffService
{
	public function __construct(
		private readonly TariffRestrictionRepositoryInterface $tariffRestrictionRepository,
	)
	{

	}

	public function canManageTemplatePermissions(): bool
	{
		return Bitrix24::checkFeatureEnabled($this->getTemplatesAccessPermissionsFeatureId());
	}

	public function canCreateDependence(int $userId): bool
	{
		if (Bitrix24\Task::checkFeatureEnabled(Bitrix24\FeatureDictionary::TASKS_GANTT))
		{
			return true;
		}

		if ($this->isLimitExceed())
		{
			return $this->tariffRestrictionRepository->getGanttLinkCount($userId) < 5;
		}

		return true;
	}

	public function isDemo(): bool
	{
		return Loader::includeModule('bitrix24') && CBitrix24::IsDemoLicense();
	}

	public function isEnabled(string $featureName): bool
	{
		return Bitrix24::checkFeatureEnabled($featureName);
	}

	public function getMailUserFeatureId(): string
	{
		return Bitrix24\FeatureDictionary::TASK_MAIL_USER_INTEGRATION;
	}

	public function getStakeholderFeatureId(): string
	{
		return Bitrix24\FeatureDictionary::TASK_OBSERVERS_PARTICIPANTS;
	}

	public function isStakeholderAvailable(): bool
	{
		return Bitrix24::checkFeatureEnabled(Bitrix24\FeatureDictionary::TASK_OBSERVERS_PARTICIPANTS);
	}

	public function getCrmIntegrationFeatureId(): string
	{
		return Bitrix24\FeatureDictionary::TASK_CRM_INTEGRATION;
	}

	public function getTasksRecurrentFeatureId(): string
	{
		return Bitrix24\FeatureDictionary::TASK_RECURRING_TASKS;
	}

	public function getRelatedSubtaskDeadlinesFeatureId(): string
	{
		return Bitrix24\FeatureDictionary::TASK_RELATED_SUBTASK_DEADLINES;
	}

	public function getRequiredResultFeatureId(): string
	{
		return Bitrix24\FeatureDictionary::TASK_STATUS_SUMMARY;
	}

	public function getControlFeatureId(): string
	{
		return Bitrix24\FeatureDictionary::TASK_CONTROL;
	}

	public function getTimeTrackingFeatureId(): string
	{
		return Bitrix24\FeatureDictionary::TASK_TIME_TRACKING;
	}

	public function getDelegatingFeatureId(): string
	{
		return Bitrix24\FeatureDictionary::TASK_DELEGATING;
	}

	public function getSkipWeekendsFeatureId(): string
	{
		return Bitrix24\FeatureDictionary::TASK_SKIP_WEEKENDS;
	}

	public function getTimeElapsedFeatureId(): string
	{
		return Bitrix24\FeatureDictionary::TASK_TIME_ELAPSED;
	}

	public function getRecurringTasksFeatureId(): string
	{
		return Bitrix24\FeatureDictionary::TASK_RECURRING_TASKS;
	}

	public function getTemplatesSubtasksFeatureId(): string
	{
		return Bitrix24\FeatureDictionary::TASK_TEMPLATES_SUBTASKS;
	}

	public function getTemplatesAccessPermissionsFeatureId(): string
	{
		return Bitrix24\FeatureDictionary::TASK_TEMPLATE_ACCESS_PERMISSIONS;
	}

	public function getRobotsFeatureId(): string
	{
		return Bitrix24\FeatureDictionary::TASK_ROBOTS;
	}

	public function getMarkFeatureId(): string
	{
		return Bitrix24\FeatureDictionary::TASK_RATE;
	}

	public function getProjectFeatureId(): string
	{
		return ProjectLimit::getFeatureId();
	}

	public function isProjectAvailable(int $groupId = 0): bool
	{
		return ProjectLimit::isFeatureEnabled($groupId);
	}

	public function isLimitExceed(): bool
	{
		return Limit::isLimitExceeded();
	}
}
