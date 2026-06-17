<?php

declare(strict_types=1);

namespace Bitrix\Crm\Copilot\Pipeline\Scenario;

use Bitrix\Crm\Integration\AI\AIManager;
use Bitrix\Crm\Integration\AI\Enum\GlobalSetting;
use Bitrix\Crm\Integration\AI\Operation\FillItemFieldsFromCallTranscription;
use Bitrix\Crm\Integration\AI\Operation\Scenario;
use Bitrix\Crm\Integration\AI\Operation\SummarizeCallTranscription;
use Bitrix\Crm\Integration\AI\Operation\TranscribeCallRecording;

final class FillFieldsScenario extends AbstractScenario
{
	public function getId(): string
	{
		return Scenario::FILL_FIELDS_SCENARIO;
	}

	public function getSteps(): array
	{
		return [
			TranscribeCallRecording::class,
			SummarizeCallTranscription::class,
			FillItemFieldsFromCallTranscription::class,
		];
	}

	public function getStepsWithSkipTranscription(): array
	{
		return [
			SummarizeCallTranscription::class,
			FillItemFieldsFromCallTranscription::class,
		];
	}

	public function canSkipTranscription(?string $activityProvider): bool
	{
		return $activityProvider !== null && !Scenario::isScenarioRequiresTranscription($activityProvider);
	}

	public function isEnabled(): bool
	{
		// FillItemFields depends on SummarizeCallTranscription as its parent step (hard constructor precondition).
		// Disabling Summarize globally makes FillFields non-runnable — reflect that at scenario level.
		return AIManager::isEnabledInGlobalSettings(GlobalSetting::FillItemFromCall)
			&& AIManager::isEnabledInGlobalSettings(GlobalSetting::Summarize)
		;
	}

	public function getDisabledSliderCode(): ?string
	{
		return Scenario::FILL_FIELDS_SCENARIO_OFF_SLIDER_CODE;
	}
}
