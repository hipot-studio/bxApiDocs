<?php

namespace Bitrix\Mobile\Tab\Preset\Dto;

use Bitrix\Mobile\Tab\Preset\Enum\PresetSource;

final class ResolvedTabPreset
{
	public function __construct(
		public readonly array $config,
		public readonly PresetSource $source,
		public readonly string $presetId,
	)
	{
	}

	public static function fromState(array $config, array $state): ?self
	{
		$source = PresetSource::tryFrom((string)($state['CURRENT_SOURCE'] ?? ''));
		if ($source === null)
		{
			return null;
		}

		$managedPresetId = (int)($state['CURRENT_PRESET_ID'] ?? 0);
		$builtinPresetId = (string)($state['CURRENT_BUILTIN_ID'] ?? '');
		$presetId = match (true)
		{
			$managedPresetId > 0 => 'managed:' . $managedPresetId,
			$builtinPresetId !== '' => $builtinPresetId,
			$source === PresetSource::Manual => 'manual',
			default => 'default',
		};

		return new self($config, $source, $presetId);
	}

	public static function fromPresetId(array $config, string $presetId): self
	{
		$source = match (true)
		{
			str_starts_with($presetId, 'managed:') => PresetSource::Managed,
			$presetId === 'manual' => PresetSource::Manual,
			default => PresetSource::Builtin,
		};

		return new self($config, $source, $presetId);
	}

	public function getLegacyPresetName(): string
	{
		if (
			in_array($this->source, [PresetSource::Manual, PresetSource::Managed, PresetSource::AppliedDeleted], true)
			|| ($this->source === PresetSource::Intent && str_starts_with($this->presetId, 'managed:'))
		)
		{
			return 'manual';
		}

		return $this->presetId;
	}
}
