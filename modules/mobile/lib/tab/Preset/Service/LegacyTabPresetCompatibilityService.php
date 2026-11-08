<?php

namespace Bitrix\Mobile\Tab\Preset\Service;

use Bitrix\Mobile\Context;
use Bitrix\Mobile\Tab\Manager;
use Bitrix\Mobile\Tab\Preset\Dto\ResolvedTabPreset;

final class LegacyTabPresetCompatibilityService
{
	private ?TabPresetStateWriter $stateWriter;
	private readonly bool $legacySnapshotManagerRequired;
	private ?Manager $legacySnapshotManager = null;
	private readonly \Closure $legacySnapshotManagerFactory;
	private readonly \Closure $resolvedPresetResolver;
	private ?array $resolvedState = null;

	public function __construct(
		private readonly ActiveTabProvider $activeTabProvider,
		private readonly Context $context,
		private readonly UserPresetStateService $stateService = new UserPresetStateService(),
		?TabPresetStateWriter $stateWriter = null,
		?\Closure $legacySnapshotManagerFactory = null,
		?\Closure $activeTabsResolver = null,
		?\Closure $portalDefaultResolver = null,
		private readonly PresetWriteTransaction $transaction = new PresetWriteTransaction(),
		?\Closure $resolvedPresetResolver = null,
	)
	{
		$this->stateWriter = $stateWriter;
		$this->legacySnapshotManagerRequired = $stateWriter !== null;
		$this->legacySnapshotManagerFactory = $legacySnapshotManagerFactory
			?? fn(): Manager => new Manager($this->context);
		if ($resolvedPresetResolver !== null)
		{
			$this->resolvedPresetResolver = $resolvedPresetResolver;
		}
		elseif ($activeTabsResolver !== null || $portalDefaultResolver !== null)
		{
			$activeTabsResolver ??= $this->activeTabProvider->getActiveTabs(...);
			$portalDefaultResolver ??= fn(): ?array => (new PortalDefaultPresetService($this->getManager()))
				->getDefault((string)$this->context->siteId)
			;
			$this->resolvedPresetResolver = fn(): ResolvedTabPreset => $this->resolveWithLegacyDependencies(
				$activeTabsResolver,
				$portalDefaultResolver,
			);
		}
		else
		{
			$this->resolvedPresetResolver = $this->activeTabProvider->getResolvedPreset(...);
		}
	}

	public static function forContext(Context $context): self
	{
		return new self(ActiveTabProvider::forContext($context), $context);
	}

	public function getManager(): Manager
	{
		return $this->activeTabProvider->getManager();
	}

	public function getActiveTabs(): array
	{
		return $this->getResolvedState()['activeTabs'];
	}

	public function getCurrentPresetName(): string
	{
		return $this->getResolvedState()['currentPresetName'];
	}

	public function getResolvedState(): array
	{
		if ($this->resolvedState !== null)
		{
			return $this->resolvedState;
		}

		/** @var ResolvedTabPreset $resolvedPreset */
		$resolvedPreset = ($this->resolvedPresetResolver)();

		return $this->resolvedState = [
			'activeTabs' => $resolvedPreset->config,
			'currentPresetName' => $resolvedPreset->getLegacyPresetName(),
		];
	}

	public function setConfig(array $config): array
	{
		if (!$this->activeTabProvider->isManagedTabPresetsEnabled())
		{
			$config = $this->transaction->run(function () use ($config): array {
				$config = $this->getManager()->setCustomConfig($config, true);
				$this->stateService->saveManualState(
					(int)$this->context->userId,
					(string)$this->context->siteId,
					$config,
				);
				$this->invalidateActiveTabsAfterCommit();

				return $config;
			});
			$this->resolvedState = null;

			return $config;
		}

		$config = $this->getStateWriter()->setCustomConfig($config, true);
		if ($this->legacySnapshotManagerRequired)
		{
			$this->getLegacySnapshotManager()->setCustomConfig($config, true);
		}
		$this->resolvedState = null;

		return $config;
	}

	public function setPreset(string $presetId): ?array
	{
		if (!$this->activeTabProvider->isManagedTabPresetsEnabled())
		{
			$config = $this->transaction->run(function () use ($presetId): ?array {
				$config = $this->getManager()->setPresetName($presetId, true);
				if ($config === null)
				{
					return null;
				}

				$this->stateService->saveBuiltinSnapshotState(
					(int)$this->context->userId,
					(string)$this->context->siteId,
					$presetId,
					$config,
				);
				$this->invalidateActiveTabsAfterCommit();

				return $config;
			});
			if ($config !== null)
			{
				$this->resolvedState = null;
			}

			return $config;
		}

		$config = $this->getStateWriter()->setPreset($presetId, true);
		if ($config === null)
		{
			return null;
		}

		if (!$this->legacySnapshotManagerRequired)
		{
			$this->resolvedState = null;

			return $config;
		}

		$legacySnapshotManager = $this->getLegacySnapshotManager();
		if (str_starts_with($presetId, 'managed:'))
		{
			$legacySnapshotManager->setCustomConfig($config, true);
			$this->resolvedState = null;

			return $config;
		}

		if ($legacySnapshotManager->setPresetName($presetId, true) === null)
		{
			$legacySnapshotManager->setCustomConfig($config, true);
		}
		$this->resolvedState = null;

		return $config;
	}

	private function getStateWriter(): TabPresetStateWriter
	{
		return $this->stateWriter ??= new CompatibleTabPresetStateWriter(
			manager: $this->getManager(),
			userId: (int)$this->context->userId,
			siteId: (string)$this->context->siteId,
			stateService: $this->stateService,
		);
	}

	private function resolveWithLegacyDependencies(
		\Closure $activeTabsResolver,
		\Closure $portalDefaultResolver,
	): ResolvedTabPreset
	{
		$manager = $this->getManager();
		$activeTabs = $activeTabsResolver();
		if (
			!$this->activeTabProvider->isManagedTabPresetsEnabled()
			|| $manager->getManagedFixedPresetConfig() !== null
		)
		{
			return ResolvedTabPreset::fromPresetId($activeTabs, $manager->getPresetName() ?: 'default');
		}

		$state = $this->stateService->getState((int)$this->context->userId, (string)$this->context->siteId);
		if (is_array($state) && $this->stateService->getAppliedConfig($state) !== null)
		{
			return ResolvedTabPreset::fromState($activeTabs, $state)
				?? ResolvedTabPreset::fromPresetId($activeTabs, 'default');
		}

		$portalDefault = $portalDefaultResolver();
		if (
			is_string($portalDefault['presetId'] ?? null)
			&& is_array($portalDefault['config'] ?? null)
			&& $portalDefault['config'] === $activeTabs
		)
		{
			return ResolvedTabPreset::fromPresetId($activeTabs, $portalDefault['presetId']);
		}

		return ResolvedTabPreset::fromPresetId($activeTabs, 'default');
	}

	private function getLegacySnapshotManager(): Manager
	{
		return $this->legacySnapshotManager ??= ($this->legacySnapshotManagerFactory)();
	}

	private function invalidateActiveTabsAfterCommit(): void
	{
		$this->transaction->afterCommit(function (): void {
			ActiveTabRequestCache::clearUser((int)$this->context->userId);
		});
	}
}
