<?php

declare(strict_types = 1);

namespace Bitrix\Main\UpdateSystem\Migration;

class ConfigFactory
{
	private static ?Config $defaultConfig = null;

	public static function getConfig(): ?Config
	{
		$config = self::$defaultConfig;
		if ($config !== null)
		{
			return $config;
		}

		return self::tryCreateForCurrentUpdater();
	}

	public static function setDefaultConfig(Config $config): void
	{
		self::$defaultConfig = $config;
	}

	public static function clearDefaultConfig(): void
	{
		self::$defaultConfig = null;
	}

	private static function tryCreateForCurrentUpdater(): ?Config
	{
		$updaterConfig = \CUpdater::getCurrentConfig();
		if (
			is_array($updaterConfig)
			&& isset($updaterConfig['moduleId'])
			&& isset($updaterConfig['updaterFilename'])
		)
		{
			$config = new Config(
				$updaterConfig['moduleId'],
				$updaterConfig['updaterFilename'],
				$updaterConfig['canUpdateKernel'] ?? false,
				$updaterConfig['canUpdatePersonalFiles'] ?? false,
			);

			$databaseUpdateMode = DatabaseUpdateMode::Disabled;
			if ($updaterConfig['canUpdateDatabase'] ?? false)
			{
				$databaseUpdateMode = DatabaseUpdateMode::Full;
			}
			elseif ($updaterConfig['canRunDdlQuery'] ?? false)
			{
				$databaseUpdateMode = DatabaseUpdateMode::DdlOnly;
			}

			$config
				->setDatabaseUpdateMode($databaseUpdateMode)
				->setUpdaterRootDirectory($updaterConfig['updaterRootDirectory'])
			;

			return $config;
		}

		return null;
	}
}
