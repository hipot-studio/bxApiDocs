<?php

namespace Bitrix\Mobile\Tab\Preset\Service;

use Bitrix\Main\Application;
use Bitrix\Mobile\Tab\Manager;

final class TabPresetConfigProvider
{
	private array $config;

	public function __construct(private readonly Manager $manager)
	{
		$this->config = include(Application::getDocumentRoot() . Manager::configPath);
	}

	public function getPresetConfigByName(string $presetName): ?array
	{
		$config = $this->config['presets'][$presetName] ?? null;
		if (!is_array($config))
		{
			return null;
		}

		return $this->normalizePresetConfig($config);
	}

	public function getDefaultUserPresetConfig(): array
	{
		return $this->getConfigByKey('defaultUserPreset');
	}

	public function getDefaultCollaberPresetConfig(): array
	{
		return $this->getConfigByKey('defaultCollaberPreset');
	}

	public function getDefaultGuestPresetConfig(): array
	{
		return $this->getConfigByKey('defaultGuestPreset');
	}

	public function getLegacyPresetMigrationConfig(): array
	{
		$config = $this->config['presetLegacy'] ?? [];

		return is_array($config) ? $config : [];
	}

	public function normalizePresetConfig(array $config): array
	{
		$required = $this->manager->getRequiredTabs();
		$result = array_keys($required);
		$unchangeable = is_array($this->config['unchangeable'] ?? null) ? $this->config['unchangeable'] : [];
		$configKeys = array_diff(array_keys($config), $result);
		$sorts = array_merge($required, $config, $unchangeable);

		$tabs = array_reduce($configKeys, function ($result, $tabId) {
			if (count($result) < Manager::maxCount && $this->manager->getTabAvailabilityState($tabId))
			{
				$result[] = $tabId;
			}

			return $result;
		}, $result);

		$result = array_filter($sorts, function ($tabId) use ($tabs) {
			return in_array($tabId, $tabs, true) && $this->manager->getTabAvailabilityState($tabId);
		}, ARRAY_FILTER_USE_KEY);

		asort($result);

		return $result;
	}

	private function getConfigByKey(string $key): array
	{
		$config = $this->config[$key] ?? [];

		return is_array($config) ? $this->normalizePresetConfig($config) : [];
	}
}
