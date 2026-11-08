<?php

namespace Bitrix\Mobile\Tab\Preset\Service;

use Bitrix\Main\Config\Feature;
use Bitrix\Mobile\Context;
use Bitrix\Mobile\Public\Features\ManagedTabPresetsFeature;
use Bitrix\Mobile\Tab\Manager;
use Bitrix\Mobile\Tab\Preset\Dto\ResolvedTabPreset;

final class ActiveTabProvider
{
	private ?ManagedActiveTabProvider $managedProvider;

	public function __construct(
		private readonly Manager $manager,
		private readonly Context $context,
		private readonly bool $managedTabPresetsEnabled,
		?ManagedActiveTabProvider $managedProvider = null,
	)
	{
		$this->managedProvider = $managedProvider;
	}

	public static function forContext(Context $context, ?bool $managedTabPresetsEnabled = null): self
	{
		$managedTabPresetsEnabled ??= Feature::isEnabled(ManagedTabPresetsFeature::class);
		$manager = $managedTabPresetsEnabled
			? Manager::createManaged($context)
			: new Manager($context);

		return new self($manager, $context, $managedTabPresetsEnabled);
	}

	public function getManager(): Manager
	{
		return $this->manager;
	}

	public function isManagedTabPresetsEnabled(): bool
	{
		return $this->managedTabPresetsEnabled;
	}

	public function getActiveTabs(): array
	{
		return $this->getResolvedPreset()->config;
	}

	public function getResolvedPreset(): ResolvedTabPreset
	{
		return ActiveTabRequestCache::resolvePreset(
			(int)$this->context->userId,
			(string)$this->context->siteId,
			$this->managedTabPresetsEnabled,
			function (): ResolvedTabPreset {
				return $this->managedTabPresetsEnabled
					? $this->getManagedProvider()->getResolvedPreset()
					: ResolvedTabPreset::fromPresetId(
						$this->manager->getActiveTabs(),
						$this->manager->getPresetName() ?: 'default',
					);
			},
		);
	}

	public function getActiveTabsData(array $ids = []): array
	{
		if ($ids !== [])
		{
			return $this->getTabsData($ids);
		}

		$activeTabs = $this->getActiveTabs();
		$sortByTabId = [];
		foreach ($activeTabs as $presetKey => $sort)
		{
			$tab = $this->manager->getTabInstance($presetKey);
			if ($tab !== null)
			{
				$sortByTabId[$tab->getId()] = $sort;
			}
		}

		return array_map(static function (array $tab) use ($sortByTabId): array {
			$tabId = $tab['id'] ?? null;
			if ($tabId !== null && array_key_exists($tabId, $sortByTabId))
			{
				$tab['sort'] = $sortByTabId[$tabId];
			}

			return $tab;
		}, $this->getTabsData(array_keys($activeTabs)));
	}

	private function getTabsData(array $ids): array
	{
		return $this->managedTabPresetsEnabled
			? $this->getManagedProvider()->getActiveTabsData($ids)
			: $this->manager->getActiveTabsData($ids);
	}

	private function getManagedProvider(): ManagedActiveTabProvider
	{
		return $this->managedProvider ??= new ManagedActiveTabProvider(
			$this->manager,
			(int)$this->context->userId,
			(string)$this->context->siteId,
		);
	}
}
