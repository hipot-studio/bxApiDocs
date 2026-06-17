<?php

declare(strict_types=1);

namespace Bitrix\Crm\Copilot\Pipeline\Scenario;

use Bitrix\Crm\Integration\AI\AIManager;
use Bitrix\Crm\Integration\AI\Enum\GlobalSetting;
use Bitrix\Crm\Integration\AI\Operation\Scenario;
use Bitrix\Crm\Integration\AI\Operation\SummarizeCallTranscription;
use Bitrix\Crm\Integration\AI\Operation\TranscribeCallRecording;

final class SummarizeScenario extends AbstractScenario
{
	public function getId(): string
	{
		return Scenario::SUMMARIZE_SCENARIO;
	}

	public function getSteps(): array
	{
		return [
			TranscribeCallRecording::class,
			SummarizeCallTranscription::class,
		];
	}

	public function canSkipTranscription(?string $activityProvider): bool
	{
		return $activityProvider !== null && !Scenario::isScenarioRequiresTranscription($activityProvider);
	}

	public function getStepsWithSkipTranscription(): array
	{
		return [
			SummarizeCallTranscription::class,
		];
	}

	public function isEnabled(): bool
	{
		return AIManager::isEnabledInGlobalSettings(GlobalSetting::Summarize);
	}
}
