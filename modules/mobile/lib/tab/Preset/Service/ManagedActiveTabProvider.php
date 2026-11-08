<?php

namespace Bitrix\Mobile\Tab\Preset\Service;

use Bitrix\Mobile\Tab\Manager;
use Bitrix\Mobile\Tab\Preset\Dto\ResolvedTabPreset;
use Bitrix\Mobile\Tab\Preset\Enum\PresetSource;

final class ManagedActiveTabProvider
{
	private readonly TabPresetConfigProvider $configProvider;
	private readonly PresetResolver $presetResolver;
	private readonly PortalDefaultPresetService $portalDefaultPresetService;

	public function __construct(
		private readonly Manager $manager,
		private readonly int $userId,
		private readonly string $siteId,
		private readonly PresetAccessPolicy $accessPolicy = new PresetAccessPolicy(),
		private readonly UserPresetStateService $stateService = new UserPresetStateService(),
		?TabPresetConfigProvider $configProvider = null,
		?PresetResolver $presetResolver = null,
		?PortalDefaultPresetService $portalDefaultPresetService = null,
	)
	{
		$this->configProvider = $configProvider ?? new TabPresetConfigProvider($this->manager);
		$this->presetResolver = $presetResolver ?? new PresetResolver(
			manager: $this->manager,
			accessPolicy: $this->accessPolicy,
			stateService: $this->stateService,
			configProvider: $this->configProvider,
		);
		$this->portalDefaultPresetService = $portalDefaultPresetService ?? new PortalDefaultPresetService(
			manager: $this->manager,
			accessPolicy: $this->accessPolicy,
			stateService: $this->stateService,
			configProvider: $this->configProvider,
		);
	}

	public function getActiveTabs(): array
	{
		return $this->getResolvedPreset()->config;
	}

	public function getResolvedPreset(): ResolvedTabPreset
	{
		$fixedContextConfig = $this->manager->getManagedFixedPresetConfig();
		if ($fixedContextConfig !== null)
		{
			return ResolvedTabPreset::fromPresetId(
				$this->configProvider->normalizePresetConfig($fixedContextConfig),
				$this->manager->getPresetName() ?: 'default',
			);
		}

		$resolvedPreset = $this->presetResolver->resolveManagedPreset($this->userId, $this->siteId);
		if ($resolvedPreset !== null && !$this->isAutomaticDefaultUserPreset($resolvedPreset))
		{
			return $resolvedPreset;
		}

		$portalDefault = $this->portalDefaultPresetService->getDefault($this->siteId);
		if (
			is_string($portalDefault['presetId'] ?? null)
			&& is_array($portalDefault['config'] ?? null)
		)
		{
			return ResolvedTabPreset::fromPresetId($portalDefault['config'], $portalDefault['presetId']);
		}

		if ($resolvedPreset !== null)
		{
			return $resolvedPreset;
		}

		return ResolvedTabPreset::fromPresetId($this->getFallbackConfig(), 'default');
	}

	public function getActiveTabsData(array $ids = []): array
	{
		$tabList = [];
		$tabConfig = null;
		if (empty($ids))
		{
			$tabConfig = $this->getActiveTabs();
			$ids = array_keys($tabConfig);
		}

		foreach ($ids as $tabId)
		{
			if (!$this->manager->getTabAvailabilityState($tabId))
			{
				continue;
			}

			$tabInstance = $this->manager->getTabInstance($tabId);
			if ($tabInstance === null)
			{
				continue;
			}

			$tab = array_merge([
				'id' => $tabInstance->getId(),
				'title' => $tabInstance->getShortTitle(),
			], $tabInstance->getData());

			if ($tabConfig !== null && array_key_exists($tabId, $tabConfig))
			{
				$tab['sort'] = $tabConfig[$tabId];
			}

			$tabList[] = $tab;
		}

		return $tabList;
	}

	private function getFallbackConfig(): array
	{
		return $this->configProvider->getDefaultUserPresetConfig();
	}

	private function isAutomaticDefaultUserPreset(ResolvedTabPreset $preset): bool
	{
		$state = $this->stateService->getState($this->userId, $this->siteId);

		return $preset->source === PresetSource::Manual
			&& empty($state['MANUALLY_SET_AT'])
			&& $preset->config === $this->configProvider->getDefaultUserPresetConfig();
	}
}
