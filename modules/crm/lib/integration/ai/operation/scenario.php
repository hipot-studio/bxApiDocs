<?php

namespace Bitrix\Crm\Integration\AI\Operation;

use Bitrix\Crm\Activity\Provider\Call;
use Bitrix\Crm\Activity\Provider\OpenLine;
use Bitrix\Crm\Copilot\Pipeline\ScenarioRegistry;
use Bitrix\Crm\Integration\AI\AIManager;
use Bitrix\Crm\Integration\AI\Enum\GlobalSetting;
use Bitrix\Main\DI\ServiceLocator;

final class Scenario
{
	// list of scenario
	public const UNDEFINED_SCENARIO = '';
	public const FULL_SCENARIO = 'full';
	public const TRANSCRIBE_RECORD_SCENARIO = 'transcribe_record';
	public const SUMMARIZE_SCENARIO = 'summarize';
	public const FILL_FIELDS_SCENARIO = 'fill_fields';
	public const CALL_SCORING_SCENARIO = 'call_scoring';
	public const EXTRACT_SCORING_CRITERIA_SCENARIO = 'extract_scoring_criteria';
	public const REPEAT_SALE_TIPS_SCENARIO = 'repeat_sale_tips';
	public const REPEAT_SALE_SCREENING_SCENARIO = 'repeat_sale_screening';
	public const CONFIRM_FIELDS_SCENARIO = 'confirm_fields';
	public const ANALYZE_COMMUNICATION_SCENARIO = 'analyze_communication';

	// slider codes
	public const FULL_OFF_SLIDER_CODE = 'limit_copilot_off';
	public const FILL_FIELDS_SCENARIO_OFF_SLIDER_CODE = 'limit_v2_crm_copilot_fill_item_from_call_off';
	public const CALL_SCORING_SCENARIO_SLIDER_CODE = 'limit_v2_crm_copilot_call_assessment_off';
	public const REPEAT_SALE_TIPS_SCENARIO_SLIDER_CODE = 'limit_crm_copilot_repeat_sale_off';

	public static function isSupportedScenario(string $scenario): bool
	{
		if ($scenario === self::CONFIRM_FIELDS_SCENARIO)
		{
			return true;
		}

		return ServiceLocator::getInstance()->get(ScenarioRegistry::class)->getByName($scenario) !== null;
	}

	public static function isScenarioRequiresTranscription(?string $providerId): bool
	{
		return $providerId === Call::getId();
	}

	public static function isEnabledScenario(string $scenario): bool
	{
		if ($scenario === self::CONFIRM_FIELDS_SCENARIO)
		{
			return true;
		}

		return ServiceLocator::getInstance()
			->get(ScenarioRegistry::class)
			->getByName($scenario)
			?->isEnabled() ?? false
		;
	}

	public static function getDisabledSliderCode(string $scenario): ?string
	{
		return ServiceLocator::getInstance()
			->get(ScenarioRegistry::class)
			->getByName($scenario)
			?->getDisabledSliderCode()
		;
	}

	public static function isManualFullScenarioAvailable(?string $providerId): bool
	{
		$requiredSettings = match ($providerId)
		{
			Call::ACTIVITY_PROVIDER_ID => [
				GlobalSetting::FillItemFromCall,
				GlobalSetting::CallAssessment,
				GlobalSetting::AnalyzeCommunication,
				GlobalSetting::Summarize,
			],
			OpenLine::ACTIVITY_PROVIDER_ID => [
				GlobalSetting::FillItemFromCall,
				GlobalSetting::AnalyzeCommunication,
				GlobalSetting::Summarize,
			],
			default => null,
		};

		if ($requiredSettings === null)
		{
			return false;
		}

		foreach ($requiredSettings as $setting)
		{
			if (!AIManager::isEnabledInGlobalSettings($setting))
			{
				return false;
			}
		}

		return true;
	}

	public static function filterFullScenarioByGlobalSettings(string $scenario): string
	{
		if ($scenario === self::FULL_SCENARIO)
		{
			$fillFieldsEnabled = self::isEnabledScenario(self::FILL_FIELDS_SCENARIO);
			$callScoringEnabled = self::isEnabledScenario(self::CALL_SCORING_SCENARIO);
			$analyzeCommunicationEnabled = self::isEnabledScenario(self::ANALYZE_COMMUNICATION_SCENARIO);

			if (
				$fillFieldsEnabled
				&& !$callScoringEnabled
				&& !$analyzeCommunicationEnabled
			)
			{
				return self::FILL_FIELDS_SCENARIO;
			}

			if (
				$callScoringEnabled
				&& !$fillFieldsEnabled
				&& !$analyzeCommunicationEnabled
			)
			{
				return self::CALL_SCORING_SCENARIO;
			}

			if (
				$analyzeCommunicationEnabled
				&& !$fillFieldsEnabled
				&& !$callScoringEnabled
			)
			{
				return self::ANALYZE_COMMUNICATION_SCENARIO;
			}

			// Summarize-only fallback: if none of the above single-scenario conditions matched
			// (e.g. multiple scenarios are enabled, or only Summarize is enabled).
			// When only Summarize is active, downgrade from FULL to SUMMARIZE.
			if (
				!$fillFieldsEnabled
				&& !$callScoringEnabled
				&& !$analyzeCommunicationEnabled
				&& self::isEnabledScenario(self::SUMMARIZE_SCENARIO)
			)
			{
				return self::SUMMARIZE_SCENARIO;
			}
		}

		return $scenario;
	}

	/**
	 * Returns the TYPE_ID of the second step in the scenario, used as NEXT_TYPE_ID in QueueTable.
	 *
	 * FULL_SCENARIO returns null — this is intentional backward compatibility.
	 * When operations are launched via AIManager (not PipelineExecutor), NEXT_TYPE_ID = null
	 * tells ScenarioResolver to use the legacy fallback that checks if FULL is enabled.
	 * PipelineExecutor sets NEXT_TYPE_ID explicitly via setNextTypeIdOverride(), bypassing this method.
	 */
	public static function getNextTypeIdByScenario(?string $scenario): ?int
	{
		if ($scenario === null || $scenario === self::FULL_SCENARIO)
		{
			return null;
		}

		$def = ServiceLocator::getInstance()->get(ScenarioRegistry::class)->getByName($scenario);
		if (!$def)
		{
			return null;
		}

		$steps = $def->getSteps();

		return count($steps) > 1 ? $steps[1]::TYPE_ID : 0;
	}
}
