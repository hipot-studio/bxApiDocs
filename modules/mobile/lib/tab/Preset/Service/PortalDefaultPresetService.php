<?php

namespace Bitrix\Mobile\Tab\Preset\Service;

use Bitrix\Main\SystemException;
use Bitrix\Main\Type\DateTime;
use Bitrix\Mobile\Tab\Manager;
use Bitrix\Mobile\Tab\Preset\Dto\PresetReference;
use Bitrix\Mobile\Tab\Preset\Dto\ResolvedTabPreset;
use Bitrix\Mobile\Tab\Preset\Enum\PresetSource;
use Bitrix\Mobile\Tab\Preset\Model\PortalDefaultPresetTable;

final class PortalDefaultPresetService
{
	public const NO_DEFAULT_PRESET_ID = '__none__';

	private readonly TabPresetConfigProvider $configProvider;
	private readonly ManagedPresetReader $managedPresetReader;

	public function __construct(
		private readonly Manager $manager,
		private readonly PresetAccessPolicy $accessPolicy = new PresetAccessPolicy(),
		private readonly UserPresetStateService $stateService = new UserPresetStateService(),
		?TabPresetConfigProvider $configProvider = null,
		private readonly PresetWriteTransaction $transaction = new PresetWriteTransaction(),
		?ManagedPresetReader $managedPresetReader = null,
	)
	{
		$this->configProvider = $configProvider ?? new TabPresetConfigProvider($this->manager);
		$this->managedPresetReader = $managedPresetReader ?? new ManagedPresetReader();
	}

	/**
	 * @throws SystemException
	 */
	public function setDefault(string $siteId, int $userId, string $presetId): void
	{
		if (!$this->accessPolicy->canSetPortalDefault($userId))
		{
			throw new SystemException('Access denied', 403);
		}

		$this->ensurePresetCanBeDefault($userId, $presetId);

		$this->transaction->run(function () use ($siteId, $presetId, $userId): void {
			$now = new DateTime();
			if (PortalDefaultPresetTable::getByPrimary($siteId)->fetch())
			{
				PortalDefaultPresetTable::update($siteId, [
					'BUILTIN_PRESET_ID' => $presetId,
					'UPDATED_BY' => $userId,
					'UPDATED_AT' => $now,
				]);
			}
			else
			{
				PortalDefaultPresetTable::add([
					'SITE_ID' => $siteId,
					'BUILTIN_PRESET_ID' => $presetId,
					'UPDATED_BY' => $userId,
					'UPDATED_AT' => $now,
				]);
			}
		});
		$this->transaction->afterCommit(static function () use ($siteId): void {
			ActiveTabRequestCache::clearSite($siteId);
		});
	}

	public function getDefaultPresetId(string $siteId): ?string
	{
		$row = PortalDefaultPresetTable::getByPrimary($siteId)->fetch();

		if (!is_array($row) || (string)$row['BUILTIN_PRESET_ID'] === self::NO_DEFAULT_PRESET_ID)
		{
			return null;
		}

		return (string)$row['BUILTIN_PRESET_ID'];
	}

	public function getDefaultConfig(string $siteId): ?array
	{
		$default = $this->getDefault($siteId);

		return $default['config'] ?? null;
	}

	public function getDefault(string $siteId): ?array
	{
		$presetId = $this->getDefaultPresetId($siteId);
		if ($presetId === null)
		{
			return null;
		}

		$resolvedPreset = $this->resolvePreset($presetId);
		if ($resolvedPreset === null)
		{
			return null;
		}

		return [
			'presetId' => $resolvedPreset->presetId,
			'config' => $resolvedPreset->config,
		];
	}

	public function resetIfManagedPresetDeleted(string $siteId, int $presetId): void
	{
		if ($presetId <= 0 || $this->getDefaultPresetId($siteId) !== 'managed:' . $presetId)
		{
			return;
		}

		$this->transaction->run(static function () use ($siteId): void {
			PortalDefaultPresetTable::update($siteId, [
				'BUILTIN_PRESET_ID' => self::NO_DEFAULT_PRESET_ID,
				'UPDATED_AT' => new DateTime(),
			]);
		});
		$this->transaction->afterCommit(static function () use ($siteId): void {
			ActiveTabRequestCache::clearSite($siteId);
		});
	}

	public function refreshManagedPresetVersion(int $presetId): void
	{
		if ($presetId <= 0)
		{
			return;
		}

		$siteIds = $this->transaction->run(function () use ($presetId): array {
			$siteIds = [];
			$result = PortalDefaultPresetTable::query()
				->setSelect(['SITE_ID'])
				->where('BUILTIN_PRESET_ID', 'managed:' . $presetId)
				->exec()
			;
			$updatedAt = new DateTime();
			while ($row = $result->fetch())
			{
				$siteId = (string)$row['SITE_ID'];
				PortalDefaultPresetTable::update($siteId, [
					'UPDATED_AT' => $updatedAt,
				]);
				$siteIds[] = $siteId;
			}

			return $siteIds;
		});
		$this->transaction->afterCommit(static function () use ($siteIds): void {
			ActiveTabRequestCache::clearSites($siteIds);
		});
	}

	/**
	 * @throws SystemException
	 */
	private function ensurePresetCanBeDefault(int $userId, string $presetId): void
	{
		$resolvedPreset = $this->resolvePreset($presetId);
		if ($resolvedPreset === null)
		{
			throw new SystemException('Preset not found', 404);
		}

		if ($resolvedPreset->source === PresetSource::Managed)
		{
			$managedPresetId = PresetReference::fromPresetId($presetId)->getManagedPresetId();
			$row = $this->managedPresetReader->getActiveById((int)$managedPresetId);
			if ((int)($row['OWNER_ID'] ?? 0) !== $userId)
			{
				throw new SystemException('Access denied', 403);
			}

			return;
		}
	}

	private function resolvePreset(string $presetId): ?ResolvedTabPreset
	{
		try
		{
			$reference = PresetReference::fromPresetId($presetId);
		}
		catch (SystemException)
		{
			return null;
		}

		if ($reference->source === PresetSource::Managed)
		{
			$row = $this->managedPresetReader->getActiveById((int)$reference->getManagedPresetId());
			$config = $row === null ? null : $this->managedPresetReader->decodeConfig($row);
			if ($config === null)
			{
				return null;
			}

			return new ResolvedTabPreset(
				config: $this->configProvider->normalizePresetConfig($config),
				source: PresetSource::Managed,
				presetId: $reference->key,
			);
		}

		if (
			$reference->source !== PresetSource::Builtin
			|| !isset($this->manager->getPresetList()[$reference->key])
		)
		{
			return null;
		}

		$config = $this->configProvider->getPresetConfigByName($reference->key);
		if ($config === null)
		{
			return null;
		}

		return new ResolvedTabPreset(
			config: $config,
			source: PresetSource::Builtin,
			presetId: $reference->key,
		);
	}
}
