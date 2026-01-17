<?php

namespace Bitrix\Tasks\V2\Public\Provider;

use Bitrix\Tasks\V2\Internal\Service\TariffService;

class TariffProvider
{
	public function __construct(
		private readonly TariffService $tariffService,
	)
	{

	}

	public function getRestrictions(): array
	{
		return [
			'project' => $this->getProjectRestriction(),
			'stakeholder' => $this->getRestrictionByFeatureId($this->tariffService->getStakeholderFeatureId()),
			'relatedSubtaskDeadlines' => $this->getRestrictionByFeatureId($this->tariffService->getRelatedSubtaskDeadlinesFeatureId()),
			'requiredResult' => $this->getRestrictionByFeatureId($this->tariffService->getRequiredResultFeatureId()),
			'control' => $this->getRestrictionByFeatureId($this->tariffService->getControlFeatureId()),
			'timeTracking' => $this->getRestrictionByFeatureId($this->tariffService->getTimeTrackingFeatureId()),
			'delegating' => $this->getRestrictionByFeatureId($this->tariffService->getDelegatingFeatureId()),
			'skipWeekends' => $this->getRestrictionByFeatureId($this->tariffService->getSkipWeekendsFeatureId()),
			'timeElapsed' => $this->getRestrictionByFeatureId($this->tariffService->getTimeElapsedFeatureId()),
			'recurringTasks' => $this->getRestrictionByFeatureId($this->tariffService->getRecurringTasksFeatureId()),
			'templatesSubtasks' => $this->getRestrictionByFeatureId($this->tariffService->getTemplatesSubtasksFeatureId()),
			'templatesAccessPermissions' => $this->getRestrictionByFeatureId($this->tariffService->getTemplatesAccessPermissionsFeatureId()),
			'robots' => $this->getRestrictionByFeatureId($this->tariffService->getRobotsFeatureId()),
			'mailUserIntegration' => $this->getRestrictionByFeatureId($this->tariffService->getMailUserFeatureId()),
			'crmIntegration' => $this->getRestrictionByFeatureId($this->tariffService->getCrmIntegrationFeatureId()),
			'mark' => $this->getRestrictionByFeatureId($this->tariffService->getMarkFeatureId()),
			'recurrentTask' => $this->getRestrictionByFeatureId($this->tariffService->getTasksRecurrentFeatureId()),
		];
	}

	private function getRestrictionByFeatureId(string $featureId): array
	{
		return [
			'available' => $this->tariffService->isEnabled($featureId),
			'featureId' => $featureId,
		];
	}

	private function getProjectRestriction(): array
	{
		return [
			'available' => $this->tariffService->isProjectAvailable(),
			'featureId' => $this->tariffService->getProjectFeatureId(),
		];
	}
}
