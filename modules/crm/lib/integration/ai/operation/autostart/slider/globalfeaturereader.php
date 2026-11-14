<?php

namespace Bitrix\Crm\Integration\AI\Operation\Autostart\Slider;

use Bitrix\AI\Tuning\Manager;
use Bitrix\Crm\Integration\AI\AIManager;
use Bitrix\Crm\Integration\AI\BaasManager;
use Bitrix\Intranet\PortalSettings;
use Bitrix\Intranet\Settings\SettingsPermission;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Loader;

class GlobalFeatureReader
{
	private const AI_SETTINGS_PAGE_QUERY = '?analyticContext=widget_settings_settings&page=ai';

	private ?bool $scenarioSetupAvailable = null;

	public function __construct(
		private readonly AutomationScenarioRegistry $registry = new AutomationScenarioRegistry(),
	)
	{}

	public function isPortalAutomationAllowed(): bool
	{
		// Legacy method name mentions calls, but this portal-level gate is reused by call and chat autostart runtime.
		return AIManager::isAiCallAutomaticProcessingAllowed() && BaasManager::hasPackage();
	}

	public function isScenarioSetupAvailable(): bool
	{
		return $this->scenarioSetupAvailable ??= $this->hasBaasPackage() || $this->isBaasMarketAvailable();
	}

	public function buildAvailability(
		bool $aiReady,
		bool $tuningEnabled,
		array $engineValues,
		bool $portalAutomationAllowed,
		bool $canEditGlobalSettings = true,
		bool $scenarioSetupAvailable = true,
	): array
	{
		$configured = $this->isConfigured($engineValues);
		$reasonCode = null;

		if (!$aiReady)
		{
			$reasonCode = 'ai_not_available';
		}
		elseif (!$tuningEnabled)
		{
			$reasonCode = 'global_disabled';
		}
		elseif (!$configured)
		{
			$reasonCode = 'engine_not_configured';
		}
		elseif (!$portalAutomationAllowed)
		{
			$reasonCode = 'subscription_required';
		}

		return [
			'tuningEnabled' => $tuningEnabled,
			'configured' => $configured,
			'automationAllowed' => $portalAutomationAllowed,
			'ready' => $aiReady && $tuningEnabled && $configured && $portalAutomationAllowed,
			'reasonCode' => $reasonCode,
			'action' => $this->buildAction($reasonCode, $canEditGlobalSettings, $scenarioSetupAvailable),
		];
	}

	public function readAll(): array
	{
		$manager = new Manager();
		$aiReady = $this->isAiReady();
		$portalAutomationAllowed = $aiReady && $this->isPortalAutomationAllowed();
		$canEditGlobalSettings = $this->canEditGlobalSettings();
		$scenarioSetupAvailable = $this->isScenarioSetupAvailable();

		return array_map(
			function ($config) use (
				$manager,
				$aiReady,
				$canEditGlobalSettings,
				$portalAutomationAllowed,
				$scenarioSetupAvailable,
			) {
				return $this->buildAvailability(
					$aiReady,
					$this->readTuningEnabled($manager, $config['tuningCode'] ?? null),
					$this->readEngineValues($manager, $config['engineCodes'] ?? []),
					$portalAutomationAllowed,
					$canEditGlobalSettings,
					$scenarioSetupAvailable,
				);
			},
			$this->registry->getGlobalConfigMap()
		);
	}

	public function isScenarioRuntimeReady(string $scenarioCode): bool
	{
		$config = $this->registry->getGlobalConfigMap()[$scenarioCode] ?? null;
		if ($config === null)
		{
			return false;
		}

		if (!$this->isAiReady() || !$this->isPortalAutomationAllowed())
		{
			return false;
		}

		if ($scenarioCode === AutomationScenarioRegistry::SCENARIO_TRANSCRIPTION)
		{
			return $this->isTranscriptionEngineConfigured();
		}

		$manager = new Manager();

		return $this->readTuningEnabled($manager, $config['tuningCode'] ?? null)
			&& $this->isConfigured($this->readEngineValues($manager, $config['engineCodes'] ?? []))
		;
	}

	protected function hasBaasPackage(): bool
	{
		return BaasManager::hasPackage();
	}

	protected function isBaasMarketAvailable(): bool
	{
		return BaasManager::isAvailable();
	}

	protected function isAiReady(): bool
	{
		return AIManager::isAvailable() && AIManager::isAiCallProcessingEnabled();
	}

	protected function getSettingsPath(): string
	{
		// Box portals keep settings at /configs/, where /settings/configs/ falls through to the site map
		return Loader::includeModule('intranet')
			? PortalSettings::getInstance()->getSettingsUrl()
			: '/settings/configs/'
		;
	}

	protected function isTranscriptionEngineConfigured(): bool
	{
		return AIManager::isCallTranscriptionEngineConfigured();
	}

	private function readEngineValues(Manager $manager, array $engineCodes): array
	{
		$engineValues = [];
		foreach ($engineCodes as $engineCode)
		{
			$engineValues[] = $manager->getItem($engineCode)?->getValue();
		}

		return $engineValues;
	}

	private function isConfigured(array $engineValues): bool
	{
		return !in_array('', $engineValues, true) && !in_array(null, $engineValues, true);
	}

	private function canEditGlobalSettings(): bool
	{
		$user = CurrentUser::get();

		if (Loader::includeModule('intranet'))
		{
			return SettingsPermission::initByUser($user)->canEdit();
		}

		return $user->isAdmin();
	}

	private function readTuningEnabled(Manager $manager, ?string $tuningCode): bool
	{
		if ($tuningCode === null)
		{
			return true;
		}

		return (bool)$manager->getItem($tuningCode)?->getValue();
	}

	private function buildAction(
		?string $reasonCode,
		bool $canEditGlobalSettings = true,
		bool $scenarioSetupAvailable = true,
	): array
	{
		return match ($reasonCode) {
			'subscription_required' => $scenarioSetupAvailable
				? [
					'type' => 'infoHelper',
					'code' => BaasManager::getEmptyPackagesSliderCode(),
				]
				: [
					'type' => 'none',
				],
			'global_disabled', 'engine_not_configured' => $canEditGlobalSettings
				? [
					'type' => 'sidePanel',
					'url' => $this->getSettingsPath() . self::AI_SETTINGS_PAGE_QUERY,
				]
				: [
					'type' => 'accessDenied',
				],
			default => [
				'type' => 'none',
			],
		};
	}
}
