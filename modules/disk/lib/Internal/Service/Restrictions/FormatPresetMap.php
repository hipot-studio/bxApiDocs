<?php
declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\Restrictions;

/**
 * Explicit map "preset -> extensions" owned by the module.
 *
 * The settings interface shows it as a hint of what a preset contains, the server uses it as a
 * reference book. Presets themselves are never applied: only the saved resulting list of
 * extensions is, so a preset extended later does not widen an already saved policy.
 */
class FormatPresetMap
{
	public const EXECUTABLES = 'executables';
	public const VIDEO = 'video';
	public const ARCHIVE = 'archive';

	protected const PRESETS = [
		self::EXECUTABLES => [
			'labelKey' => 'DISK_RESTRICTIONS_PRESET_EXECUTABLES',
			'extensions' => ['exe', 'msi', 'bat', 'cmd', 'sh', 'com', 'scr'],
		],
		self::VIDEO => [
			'labelKey' => 'DISK_RESTRICTIONS_PRESET_VIDEO',
			'extensions' => [
				'avi', 'wmv', 'mp4', 'mov', 'webm', 'flv', 'm4v', 'mkv', 'vob', '3gp', 'ogv', 'h264',
			],
		],
		self::ARCHIVE => [
			'labelKey' => 'DISK_RESTRICTIONS_PRESET_ARCHIVE',
			'extensions' => ['zip', 'rar', 'tar', 'gz', 'bz2', 'tgz', '7z'],
		],
	];

	/**
	 * Returns every preset with its id, label key and extensions.
	 *
	 * @return array
	 */
	public function getAll(): array
	{
		$presets = [];
		foreach (self::PRESETS as $id => $preset)
		{
			$presets[] = [
				'id' => $id,
				'labelKey' => $preset['labelKey'],
				'extensions' => $preset['extensions'],
			];
		}

		return $presets;
	}

	/**
	 * Returns the extensions of the preset. An unknown preset has no extensions.
	 *
	 * @param string $presetId
	 * @return array
	 */
	public function getExtensions(string $presetId): array
	{
		return self::PRESETS[$presetId]['extensions'] ?? [];
	}
}
