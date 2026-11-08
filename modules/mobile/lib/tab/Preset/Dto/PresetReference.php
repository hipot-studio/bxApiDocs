<?php

namespace Bitrix\Mobile\Tab\Preset\Dto;

use Bitrix\Main\SystemException;
use Bitrix\Mobile\Tab\Preset\Enum\PresetSource;

final class PresetReference
{
	public function __construct(
		public readonly PresetSource $source,
		public readonly string $key,
	)
	{
	}

	/**
	 * @throws SystemException
	 */
	public static function fromPresetId(string $presetId): self
	{
		$presetId = trim($presetId);
		if ($presetId === '')
		{
			throw new SystemException('Preset id is required', 422);
		}

		if (str_starts_with($presetId, 'managed:'))
		{
			$id = (int)mb_substr($presetId, 8);
			if ($id <= 0)
			{
				throw new SystemException('Managed preset id is invalid', 422);
			}

			return new self(PresetSource::Managed, 'managed:' . $id);
		}

		if ($presetId === 'manual')
		{
			return new self(PresetSource::Manual, $presetId);
		}

		return new self(PresetSource::Builtin, $presetId);
	}

	/**
	 * @throws SystemException
	 */
	public static function fromAssignmentRow(array $row): self
	{
		$source = PresetSource::tryFrom((string)($row['PRESET_SOURCE'] ?? ''));
		if ($source === null)
		{
			throw new SystemException('Preset source is invalid', 422);
		}

		$key = trim((string)($row['PRESET_KEY'] ?? ''));
		if ($key === '')
		{
			throw new SystemException('Preset key is invalid', 422);
		}

		return new self($source, $key);
	}

	public function getManagedPresetId(): ?int
	{
		if ($this->source !== PresetSource::Managed || !str_starts_with($this->key, 'managed:'))
		{
			return null;
		}

		$id = (int)mb_substr($this->key, 8);

		return $id > 0 ? $id : null;
	}

	public function isAssignable(): bool
	{
		return in_array($this->source, [PresetSource::Builtin, PresetSource::Managed], true);
	}
}
