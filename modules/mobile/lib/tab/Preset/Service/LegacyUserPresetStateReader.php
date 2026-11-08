<?php

namespace Bitrix\Mobile\Tab\Preset\Service;

use Bitrix\Main\Config\Option;
use Bitrix\Main\Web\Json;

final class LegacyUserPresetStateReader
{
	public const STATE_NONE = 'none';
	public const STATE_CUSTOM = 'custom';
	public const STATE_NAMED = 'named';
	public const STATE_CORRUPT = 'corrupt';
	public const STATE_DELETED = 'deleted';

	public function __construct(private readonly TabPresetConfigProvider $configProvider)
	{
	}

	/**
	 * @return array{kind: string, config?: array, presetId?: string, isManual?: bool}
	 */
	public function read(int $userId, string $siteId): array
	{
		if ($userId <= 0 || $siteId === '')
		{
			return ['kind' => self::STATE_NONE];
		}

		$isManual = $this->isManuallySet($userId);
		$customState = $this->readCustomState($userId, $siteId, $isManual);
		if ($customState !== null && $customState['kind'] === self::STATE_CORRUPT)
		{
			return $customState;
		}

		if ($customState !== null && $customState['kind'] === self::STATE_CUSTOM && $isManual)
		{
			return $customState;
		}

		$presetId = Option::get('mobile', "tabs_preset_{$userId}", '', $siteId);
		$migrationConfig = $this->getPendingMigrationConfig($userId, $presetId);
		if ($migrationConfig !== null)
		{
			return [
				'kind' => self::STATE_NAMED,
				'presetId' => $presetId,
				'config' => $migrationConfig,
				'isManual' => $isManual,
			];
		}

		if ($customState !== null)
		{
			return $customState;
		}

		if ($presetId === '' || $presetId === 'manual')
		{
			return ['kind' => self::STATE_NONE];
		}

		$config = $this->configProvider->getPresetConfigByName($presetId);
		if ($config === null || empty($config))
		{
			return ['kind' => self::STATE_DELETED];
		}

		return [
			'kind' => self::STATE_NAMED,
			'presetId' => $presetId,
			'config' => $config,
			'isManual' => $isManual,
		];
	}

	private function readCustomState(int $userId, string $siteId, bool $isManual): ?array
	{
		$customConfig = Option::get('mobile', "tabs_{$userId}", '', $siteId);
		if ($customConfig === '')
		{
			return null;
		}

		try
		{
			$config = Json::decode($customConfig);
		}
		catch (\Throwable)
		{
			return ['kind' => self::STATE_CORRUPT];
		}

		if (!is_array($config))
		{
			return ['kind' => self::STATE_CORRUPT];
		}
		if (empty($config))
		{
			return null;
		}

		return [
			'kind' => self::STATE_CUSTOM,
			'config' => $config,
			'isManual' => $isManual,
		];
	}

	private function getPendingMigrationConfig(int $userId, string $presetId): ?array
	{
		$migration = $this->configProvider->getLegacyPresetMigrationConfig();
		$currentVersion = (int)($migration['currentVersion'] ?? 0);
		$migrationVersion = (int)\CUserOptions::GetOption(
			'mobile',
			'tabs_presets_migration_version',
			0,
			$userId,
		);
		if ($currentVersion <= $migrationVersion)
		{
			return null;
		}

		$presetId = $presetId ?: 'default';
		$config = $migration[$presetId] ?? null;

		return is_array($config) && !empty($config) ? $config : null;
	}

	private function isManuallySet(int $userId): bool
	{
		return \CUserOptions::GetOption(
			'mobile',
			'tabs_preset_manually_set',
			'N',
			$userId,
		) === 'Y';
	}
}
