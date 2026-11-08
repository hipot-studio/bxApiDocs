<?php

namespace Bitrix\Crm\Integration\AI\Operation\Autostart\Slider;

use Bitrix\Crm\Integration\AI\AIManager;
use Bitrix\Crm\Integration\AI\Operation\Autostart\CallAssessmentDefault;
use Bitrix\Crm\Integration\AI\Operation\Autostart\FillFieldsSettings;
use Bitrix\Main\Localization\Loc;
use CCrmActivityDirection;

final readonly class AutomationSliderQueryService
{
	public function __construct(
		private AutostartSettingsRepository $repository,
		private AutomationScenarioRegistry $registry,
		private GlobalFeatureReader $featureReader,
		private CallAssessmentDefault $callAssessmentDefault = new CallAssessmentDefault(),
	)
	{
	}

	public function build(array $scope, string $languageId): array
	{
		Loc::loadMessages(__FILE__);

		$settings = $this->repository->get($scope);
		$availabilityMap = $this->featureReader->readAll();

		$callScoringV2Enabled = AIManager::isCallScoringV2Enabled();

		$scenarios = [];
		foreach ($this->registry->getAll() as $code => $scenario)
		{
			if ($code === AutomationScenarioRegistry::SCENARIO_CALL_ASSESSMENT && !$callScoringV2Enabled)
			{
				continue;
			}

			$scenarios[] = [
				'code' => $code,
				'title' => Loc::getMessage($scenario['titleId']),
				'description' => Loc::getMessage(
					$scenario['descriptionId'],
					['#COPILOT_NAME#' => AIManager::getCopilotName()],
				),
				'channels' => $this->collectChannelStates($settings, $code, $scenario),
				'availability' => $availabilityMap[$code] ?? $this->unavailableState(),
			];
		}

		return [
			'revision' => $this->repository->getRevision($scope),
			'scope' => $scope,
			'header' => [
				'languageId' => $languageId,
				'languageTitle' => AIManager::getAvailableLanguageList()[$languageId] ?? '',
				'isLanguageSelectorAvailable' => true,
			],
			'scenarios' => $scenarios,
		];
	}

	private function collectChannelStates(FillFieldsSettings $settings, string $scenarioCode, array $scenario): array
	{
		$result = [];

		foreach ($scenario['channels'] as $channelCode)
		{
			$state = $this->resolveScenarioChannelState($settings, $scenarioCode, $channelCode, $scenario);
			$availableModes = $this->registry->getAvailableModes($channelCode);

			$result[] = [
				'code' => $channelCode,
				'title' => $this->channelTitle($channelCode),
				'enabled' => $state['enabled'],
				'mode' => $state['mode'],
				'availableModes' => $availableModes,
			];
		}

		return $result;
	}

	private function resolveScenarioChannelState(
		FillFieldsSettings $settings,
		string $scenarioCode,
		string $channelCode,
		array $scenario,
	): array
	{
		$override = $settings->getScenarioOverride($scenarioCode, $channelCode);
		if (is_array($override))
		{
			return [
				'enabled' => (bool)($override['enabled'] ?? false),
				'mode' => $this->normalizeChannelMode($channelCode, $override['mode'] ?? null),
			];
		}

		if ($channelCode === AutomationScenarioRegistry::CHANNEL_EMAIL)
		{
			return [
				'enabled' => true,
				'mode' => $this->registry->getDefaultMode($channelCode),
			];
		}

		if (
			$scenarioCode === AutomationScenarioRegistry::SCENARIO_CALL_ASSESSMENT
			&& $channelCode === AutomationScenarioRegistry::CHANNEL_CALL
		)
		{
			$default = $this->callAssessmentDefault->get();

			return [
				'enabled' => (bool)($default['enabled'] ?? false),
				'mode' => $this->normalizeChannelMode($channelCode, $default['mode'] ?? null),
			];
		}

		$channelData = $settings->getChannelSettings($channelCode)?->toArray() ?? [];
		$operationType = $scenario['operationType'];
		$operationTypes = $channelData['autostartOperationTypes'] ?? [];
		$enabled = in_array($operationType, $operationTypes, true);
		$mode = $this->resolveLegacyMode($channelCode, $channelData);

		return [
			'enabled' => $enabled,
			'mode' => $mode,
		];
	}

	private function normalizeChannelMode(string $channelCode, mixed $mode): string
	{
		$mode = is_string($mode) ? $mode : '';
		if (!in_array($mode, $this->registry->getAvailableModes($channelCode), true))
		{
			return $this->registry->getDefaultMode($channelCode);
		}

		return $mode;
	}

	private function resolveLegacyMode(string $channelCode, array $channelData): string
	{
		if ($channelCode === AutomationScenarioRegistry::CHANNEL_CALL)
		{
			$directions = $channelData['autostartCallDirections'] ?? [];
			$onlyFirst = (bool)($channelData['autostartTranscriptionOnlyOnFirstCallWithRecording'] ?? false);
			$hasIncoming = in_array(CCrmActivityDirection::Incoming, $directions, true);
			$hasOutgoing = in_array(CCrmActivityDirection::Outgoing, $directions, true);

			if ($hasIncoming && $hasOutgoing)
			{
				return 'both';
			}

			if ($hasOutgoing)
			{
				return 'outgoing';
			}

			return $onlyFirst ? 'firstIncoming' : 'allIncoming';
		}

		if ($channelCode === AutomationScenarioRegistry::CHANNEL_CHAT)
		{
			return ($channelData['autostartOnlyFirstChat'] ?? false) ? 'firstChat' : 'all';
		}

		return $this->registry->getDefaultMode($channelCode);
	}

	private function unavailableState(): array
	{
		return [
			'tuningEnabled' => false,
			'configured' => false,
			'automationAllowed' => false,
			'ready' => false,
			'reasonCode' => 'ai_not_available',
			'action' => ['type' => 'none'],
		];
	}

	private function channelTitle(string $channelCode): string
	{
		return match ($channelCode) {
			AutomationScenarioRegistry::CHANNEL_CALL => Loc::getMessage('CRM_AI_AUTOMATION_SLIDER_CHANNEL_CALL'),
			AutomationScenarioRegistry::CHANNEL_CHAT => Loc::getMessage('CRM_AI_AUTOMATION_SLIDER_CHANNEL_CHAT'),
			AutomationScenarioRegistry::CHANNEL_EMAIL => Loc::getMessage('CRM_AI_AUTOMATION_SLIDER_CHANNEL_EMAIL'),
			default => $channelCode,
		};
	}
}
