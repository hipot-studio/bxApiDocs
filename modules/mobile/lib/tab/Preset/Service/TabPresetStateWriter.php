<?php

namespace Bitrix\Mobile\Tab\Preset\Service;

interface TabPresetStateWriter
{
	public function setCustomConfig(array $config, bool $isManual = true): array;

	public function setPreset(string $presetId, bool $isManual = true): ?array;
}
