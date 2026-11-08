<?php

namespace Bitrix\Mobile\Tab\Preset\Service;

use Bitrix\Mobile\Tab\Manager;

final class ManagedTabPresetStateWriter implements TabPresetStateWriter
{
	private readonly TabPresetConfigProvider $configProvider;
	private ?ManagedPresetService $managedPresetService;

	public function __construct(
		private readonly Manager $manager,
		private readonly int $userId,
		private readonly string $siteId,
		private readonly UserPresetStateService $stateService = new UserPresetStateService(),
		private readonly MobileMenuCacheInvalidator $cacheInvalidator = new MobileMenuCacheInvalidator(),
		private readonly bool $deferCacheInvalidation = false,
		?TabPresetConfigProvider $configProvider = null,
		?ManagedPresetService $managedPresetService = null,
	)
	{
		$this->configProvider = $configProvider ?? new TabPresetConfigProvider($this->manager);
		$this->managedPresetService = $managedPresetService;
	}

	public function setCustomConfig(array $config, bool $isManual = true): array
	{
		$config = array_map(static function ($sort): int {
			return min((int)$sort, Manager::maxSortValue - 1);
		}, $config);
		$config = $this->configProvider->normalizePresetConfig($config);
		if ($isManual)
		{
			$this->stateService->saveManualState($this->userId, $this->siteId, $config);
		}
		elseif (!$this->stateService->saveCheckoutIntentState($this->userId, $this->siteId, 'manual', $config))
		{
			throw new AutomaticPresetStateWriteRejectedException();
		}
		$this->clearUserCache();

		return $config;
	}

	public function setPreset(string $presetId, bool $isManual = true): ?array
	{
		if (str_starts_with($presetId, 'managed:'))
		{
			return $this->getManagedPresetService()->selectForUser(
				$this->userId,
				$this->siteId,
				(int)mb_substr($presetId, 8),
				$isManual,
				!$this->deferCacheInvalidation,
			);
		}

		$config = $this->configProvider->getPresetConfigByName($presetId);
		if ($config === null)
		{
			return null;
		}

		$this->setBuiltinSnapshot($presetId, $config, $isManual);

		return $config;
	}

	public function setBuiltinSnapshot(string $presetId, array $config, bool $isManual = true): array
	{
		$config = $this->configProvider->normalizePresetConfig($config);
		if ($isManual)
		{
			$this->stateService->saveBuiltinSnapshotState(
				$this->userId,
				$this->siteId,
				$presetId,
				$config,
			);
		}
		elseif (!$this->stateService->saveCheckoutIntentState($this->userId, $this->siteId, $presetId, $config))
		{
			throw new AutomaticPresetStateWriteRejectedException();
		}
		$this->clearUserCache();

		return $config;
	}

	private function getManagedPresetService(): ManagedPresetService
	{
		return $this->managedPresetService ??= new ManagedPresetService(
			$this->manager,
			stateService: $this->stateService,
		);
	}

	private function clearUserCache(): void
	{
		if (!$this->deferCacheInvalidation)
		{
			$this->cacheInvalidator->clearUser($this->userId);
		}
	}
}
