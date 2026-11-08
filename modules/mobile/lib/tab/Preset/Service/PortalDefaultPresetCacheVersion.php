<?php

namespace Bitrix\Mobile\Tab\Preset\Service;

use Bitrix\Main\Type\DateTime;
use Bitrix\Main\Web\Json;
use Bitrix\Mobile\Tab\Preset\Dto\PresetReference;
use Bitrix\Mobile\Tab\Preset\Enum\PresetSource;
use Bitrix\Mobile\Tab\Preset\Model\PortalDefaultPresetTable;

final class PortalDefaultPresetCacheVersion
{
	private const CACHE_TTL = 2592000;
	private readonly ManagedPresetReader $managedPresetReader;

	public function __construct(?ManagedPresetReader $managedPresetReader = null)
	{
		$this->managedPresetReader = $managedPresetReader ?? new ManagedPresetReader();
	}

	public function get(string $siteId): string
	{
		$row = PortalDefaultPresetTable::getByPrimary($siteId, [
			'select' => ['BUILTIN_PRESET_ID', 'UPDATED_AT'],
			'cache' => ['ttl' => self::CACHE_TTL],
		])->fetch();
		if (!is_array($row))
		{
			return '0';
		}

		$presetId = (string)($row['BUILTIN_PRESET_ID'] ?? '');
		$portalUpdatedAtVersion = $this->getDateTimeVersion($row['UPDATED_AT'] ?? null);
		$version = $presetId . ':' . $portalUpdatedAtVersion;

		try
		{
			$reference = PresetReference::fromPresetId($presetId);
			if ($reference->source === PresetSource::Managed)
			{
				$managedPreset = $this->managedPresetReader->getCacheVersionDataById(
					(int)$reference->getManagedPresetId(),
					self::CACHE_TTL,
				);
				if ($managedPreset === null)
				{
					return $version . ':missing';
				}

				$managedUpdatedAtVersion = $this->getDateTimeVersion($managedPreset['UPDATED_AT'] ?? null);
				$managedContentVersion = hash('sha256', Json::encode([
					'configJson' => (string)($managedPreset['CONFIG_JSON'] ?? ''),
					'isDeleted' => (string)($managedPreset['IS_DELETED'] ?? 'N'),
				]));

				return $version . ':' . $managedUpdatedAtVersion . ':' . $managedContentVersion;
			}
		}
		catch (\Throwable)
		{
			return $version;
		}

		return $version;
	}

	private function getDateTimeVersion(mixed $value): string
	{
		return $value instanceof DateTime ? (string)$value->getTimestamp() : '0';
	}
}
