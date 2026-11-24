<?php

namespace Bitrix\Im\V2\Settings\Sound;

use Bitrix\Im\V2\Settings\Entity;

/**
 * Sound volume levels live next to the legacy `enableSound` flag: levels are the source of truth,
 * the flag is derived from them for clients that know nothing about volume.
 */
final class VolumeLevels
{
	/**
	 * Adds the six sound keys to general settings, deriving missing levels from the stored
	 * legacy flag, and replaces that flag with the value derived from the levels.
	 */
	public static function fill(array $generalSettings): array
	{
		$isLegacySoundEnabled = self::isLegacySoundEnabled(
			$generalSettings[Entity\General::ENABLE_SOUND] ?? true
		);

		foreach (SoundCategory::cases() as $category)
		{
			$name = $category->settingName();
			$generalSettings[$name] = isset($generalSettings[$name])
				? self::normalizeLevel($generalSettings[$name])
				: self::deriveLevel($category, $isLegacySoundEnabled)
			;
		}

		$generalSettings[Entity\General::SOUND_MUTED] = self::isMuted($generalSettings);
		$generalSettings[Entity\General::ENABLE_SOUND] = self::isAudible($generalSettings);

		return $generalSettings;
	}

	/**
	 * Translates a write of the legacy flag into the sound keys that have to be stored. The flag
	 * says what the old surfaces hear, so the levels it means are the ones a read derives from it.
	 * A flag repeating what is already stored carries no change and writes nothing: old forms send
	 * it back on every save, and such a save must leave the sound alone.
	 */
	public static function fromLegacyWrite(bool $enableSound, array $currentSettings): array
	{
		$currentSettings = self::fill($currentSettings);
		if ($currentSettings[Entity\General::ENABLE_SOUND] === $enableSound)
		{
			return [];
		}

		$settings = [];
		foreach (SoundCategory::cases() as $category)
		{
			$settings[$category->settingName()] = self::deriveLevel($category, $enableSound);
		}

		// Switching the sound off is the state the levels above describe, not the mute button,
		// which stays whatever the user set it to.
		if ($enableSound && $currentSettings[Entity\General::SOUND_MUTED])
		{
			$settings[Entity\General::SOUND_MUTED] = false;
		}

		return $settings;
	}

	/**
	 * Rebuilds the sound part of a write coming from a surface that knows the legacy flag only:
	 * such a surface sends back the levels it last read, so they are dropped and the flag alone
	 * decides what is stored. The flag itself is derived on read and is never written.
	 */
	public static function fromLegacySettingsWrite(array $settings, array $currentSettings): array
	{
		if (!array_key_exists(Entity\General::ENABLE_SOUND, $settings))
		{
			return $settings;
		}

		$soundSettings = self::fromLegacyWrite(
			self::isLegacySoundEnabled($settings[Entity\General::ENABLE_SOUND]),
			$currentSettings
		);

		unset($settings[Entity\General::ENABLE_SOUND]);
		foreach (self::soundKeys() as $name)
		{
			unset($settings[$name]);
		}

		return array_merge($settings, $soundSettings);
	}

	/**
	 * @return string[] the setting names the sound state is stored under
	 */
	public static function soundKeys(): array
	{
		$keys = [Entity\General::SOUND_MUTED];
		foreach (SoundCategory::cases() as $category)
		{
			$keys[] = $category->settingName();
		}

		return $keys;
	}

	public static function isSoundKey(string $name): bool
	{
		return in_array($name, self::soundKeys(), true);
	}

	/**
	 * The legacy flag comes as a bool from the settings storage and as 'Y'/'N' from old surfaces.
	 */
	public static function isLegacySoundEnabled($value): bool
	{
		return !($value === 'N' || $value === false);
	}

	private static function deriveLevel(SoundCategory $category, bool $isLegacySoundEnabled): int
	{
		if ($isLegacySoundEnabled)
		{
			return Entity\General::SOUND_VOLUME_MAX;
		}

		return match ($category)
		{
			SoundCategory::General, SoundCategory::Calls => Entity\General::SOUND_VOLUME_MAX,
			default => 0,
		};
	}

	private static function normalizeLevel($value): int
	{
		if (!is_numeric($value))
		{
			return Entity\General::SOUND_VOLUME_MAX;
		}

		return max(0, min(Entity\General::SOUND_VOLUME_MAX, (int)$value));
	}

	private static function isMuted(array $settings): bool
	{
		$value = $settings[Entity\General::SOUND_MUTED] ?? false;

		return $value === 'Y' || $value === true;
	}

	private static function isAudible(array $settings): bool
	{
		return !$settings[Entity\General::SOUND_MUTED]
			&& $settings[Entity\General::SOUND_VOLUME_GENERAL] > 0
			&& $settings[Entity\General::SOUND_VOLUME_MESSAGES] > 0
		;
	}
}
