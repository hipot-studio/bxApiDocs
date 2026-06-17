<?php

declare(strict_types=1);

namespace Bitrix\Crm\Copilot\Pipeline\Scenario;

use Bitrix\Crm\Integration\AI\AIManager;
use Bitrix\Crm\Integration\AI\Enum\GlobalSetting;
use Bitrix\Crm\Integration\AI\Operation\AnalyzeCommunication;
use Bitrix\Crm\Integration\AI\Operation\Scenario;
use Bitrix\Crm\Integration\AI\Operation\TranscribeCallRecording;

final class AnalyzeCommunicationScenario extends AbstractScenario
{
	public function getId(): string
	{
		return Scenario::ANALYZE_COMMUNICATION_SCENARIO;
	}

	public function getSteps(): array
	{
		return [
			TranscribeCallRecording::class,
			AnalyzeCommunication::class,
		];
	}

	public function getStepsWithSkipTranscription(): array
	{
		return [
			AnalyzeCommunication::class,
		];
	}

	public function canSkipTranscription(?string $activityProvider): bool
	{
		return $activityProvider !== null && !Scenario::isScenarioRequiresTranscription($activityProvider);
	}

	public function isEnabled(): bool
	{
		return AIManager::isEnabledInGlobalSettings(GlobalSetting::AnalyzeCommunication);
	}
}
