<?php

namespace Bitrix\Mobile\Tab\Preset\Service;

use Bitrix\Mobile\Tab\Manager;

final class CompatibleTabPresetStateWriter implements TabPresetStateWriter
{
	private readonly ManagedTabPresetStateWriter $managedStateWriter;
	private readonly LegacyTabPresetSnapshotStore $legacySnapshotStore;
	private ?LegacyUserPresetStateReader $legacyStateReader;

	public function __construct(
		private readonly Manager $manager,
		private readonly int $userId,
		private readonly string $siteId,
		private readonly UserPresetStateService $stateService = new UserPresetStateService(),
		private readonly MobileMenuCacheInvalidator $cacheInvalidator = new MobileMenuCacheInvalidator(),
		private readonly PresetWriteTransaction $transaction = new PresetWriteTransaction(),
		?ManagedTabPresetStateWriter $managedStateWriter = null,
		?LegacyTabPresetSnapshotStore $legacySnapshotStore = null,
		?LegacyUserPresetStateReader $legacyStateReader = null,
	)
	{
		$this->managedStateWriter = $managedStateWriter ?? new ManagedTabPresetStateWriter(
			manager: $manager,
			userId: $userId,
			siteId: $siteId,
			stateService: $this->stateService,
			cacheInvalidator: $this->cacheInvalidator,
			deferCacheInvalidation: true,
		);
		$this->legacySnapshotStore = $legacySnapshotStore ?? new LegacyTabPresetSnapshotWriter($userId, $siteId);
		$this->legacyStateReader = $legacyStateReader;
	}

	public function setCustomConfig(array $config, bool $isManual = true): array
	{
		$legacyManualConfig = $this->getLegacyManualConfig($isManual);
		if ($legacyManualConfig !== null)
		{
			return $legacyManualConfig;
		}

		try
		{
			return $this->transaction->run(function () use ($config, $isManual): array {
				$config = $this->managedStateWriter->setCustomConfig($config, $isManual);
				$this->legacySnapshotStore->saveCustomConfig($config, $isManual);
				$this->invalidateMenuCacheAfterCommit();

				return $config;
			});
		}
		catch (AutomaticPresetStateWriteRejectedException)
		{
			return $this->getCurrentConfig();
		}
	}

	public function setPreset(string $presetId, bool $isManual = true): ?array
	{
		$legacyManualConfig = $this->getLegacyManualConfig($isManual);
		if ($legacyManualConfig !== null)
		{
			return $legacyManualConfig;
		}

		try
		{
			return $this->transaction->run(function () use ($presetId, $isManual): ?array {
				$config = $this->managedStateWriter->setPreset($presetId, $isManual);
				if ($config === null)
				{
					return null;
				}

				if (str_starts_with($presetId, 'managed:'))
				{
					$this->legacySnapshotStore->saveCustomConfig($config, $isManual);
				}
				else
				{
					$this->legacySnapshotStore->savePreset($presetId, $isManual);
				}
				$this->invalidateMenuCacheAfterCommit();

				return $config;
			});
		}
		catch (AutomaticPresetStateWriteRejectedException)
		{
			return $this->getCurrentConfig();
		}
	}

	public function setBuiltinSnapshot(string $presetId, array $config, bool $isManual = true): array
	{
		$legacyManualConfig = $this->getLegacyManualConfig($isManual);
		if ($legacyManualConfig !== null)
		{
			return $legacyManualConfig;
		}

		try
		{
			return $this->transaction->run(function () use ($presetId, $config, $isManual): array {
				$config = $this->managedStateWriter->setBuiltinSnapshot($presetId, $config, $isManual);
				$this->legacySnapshotStore->saveCustomConfig($config, $isManual);
				$this->invalidateMenuCacheAfterCommit();

				return $config;
			});
		}
		catch (AutomaticPresetStateWriteRejectedException)
		{
			return $this->getCurrentConfig();
		}
	}

	private function getCurrentConfig(): array
	{
		$state = $this->stateService->getState($this->userId, $this->siteId);
		if ($state === null)
			{
				return [];
			}

		return $this->stateService->getAppliedConfig($state) ?? [];
	}

	private function getLegacyManualConfig(bool $isManualWrite): ?array
	{
		if ($isManualWrite || !$this->manager->isPresetManuallySet())
		{
			return null;
		}

		$this->legacyStateReader ??= new LegacyUserPresetStateReader(new TabPresetConfigProvider($this->manager));
		$legacyState = $this->legacyStateReader->read($this->userId, $this->siteId);
		$config = $legacyState['config'] ?? null;

		return is_array($config) ? $config : [];
	}

	private function invalidateMenuCacheAfterCommit(): void
	{
		$this->transaction->afterCommit(function (): void {
			$this->cacheInvalidator->clearUser($this->userId);
		});
	}
}
