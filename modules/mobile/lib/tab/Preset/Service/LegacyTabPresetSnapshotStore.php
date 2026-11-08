<?php

namespace Bitrix\Mobile\Tab\Preset\Service;

interface LegacyTabPresetSnapshotStore
{
	public function saveCustomConfig(array $config, bool $isManual): void;

	public function savePreset(string $presetId, bool $isManual): void;
}
