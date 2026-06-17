<?php

declare(strict_types=1);

namespace Bitrix\Crm\Copilot\Pipeline\Scenario;

use Bitrix\Crm\Integration\AI\AIManager;
use Bitrix\Crm\Integration\AI\Enum\GlobalSetting;
use Bitrix\Crm\Integration\AI\Operation\AnalyzeCommunication;
use Bitrix\Crm\Integration\AI\Operation\FillItemFieldsFromCallTranscription;
use Bitrix\Crm\Integration\AI\Operation\Scenario;
use Bitrix\Crm\Integration\AI\Operation\ScoreCall;
use Bitrix\Crm\Integration\AI\Operation\SummarizeCallTranscription;
use Bitrix\Crm\Integration\AI\Operation\TranscribeCallRecording;

final class FullScenario extends AbstractScenario
{
	public function getId(): string
	{
		return Scenario::FULL_SCENARIO;
	}

	public function getSteps(): array
	{
		return [
			TranscribeCallRecording::class,
			SummarizeCallTranscription::class,
			FillItemFieldsFromCallTranscription::class,
			ScoreCall::class,
			AnalyzeCommunication::class,
		];
	}

	public function canSkipTranscription(?string $activityProvider): bool
	{
		return $activityProvider !== null && !Scenario::isScenarioRequiresTranscription($activityProvider);
	}

	public function getStepsWithSkipTranscription(): array
	{
		// ScoreCall is omitted: it requires audio transcription and is not applicable to chat (OpenLine) context.
		return [
			SummarizeCallTranscription::class,
			FillItemFieldsFromCallTranscription::class,
			AnalyzeCommunication::class,
		];
	}

	public function isEnabled(): bool
	{
		return AIManager::isEnabledInGlobalSettings(GlobalSetting::FillItemFromCall)
			|| AIManager::isEnabledInGlobalSettings(GlobalSetting::CallAssessment)
			|| AIManager::isEnabledInGlobalSettings(GlobalSetting::AnalyzeCommunication)
			|| AIManager::isEnabledInGlobalSettings(GlobalSetting::Summarize)
		;
	}

	public function getDisabledSliderCode(): ?string
	{
		return Scenario::FULL_OFF_SLIDER_CODE;
	}
}
