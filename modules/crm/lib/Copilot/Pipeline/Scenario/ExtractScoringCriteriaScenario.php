<?php

declare(strict_types=1);

namespace Bitrix\Crm\Copilot\Pipeline\Scenario;

use Bitrix\Crm\Integration\AI\AIManager;
use Bitrix\Crm\Integration\AI\Enum\GlobalSetting;
use Bitrix\Crm\Integration\AI\Operation\ExtractScoringCriteria;
use Bitrix\Crm\Integration\AI\Operation\Scenario;

final class ExtractScoringCriteriaScenario extends AbstractScenario
{
	public function getId(): string
	{
		return Scenario::EXTRACT_SCORING_CRITERIA_SCENARIO;
	}

	public function getSteps(): array
	{
		return [
			ExtractScoringCriteria::class,
		];
	}

	public function isEnabled(): bool
	{
		return AIManager::isEnabledInGlobalSettings(GlobalSetting::CallAssessment);
	}
}
