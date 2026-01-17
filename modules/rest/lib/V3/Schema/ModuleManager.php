<?php

namespace Bitrix\Rest\V3\Schema;

use Bitrix\Main\Config\Configuration;

final class ModuleManager
{
	private const CONFIGURATION_KEY = 'rest';

	private static ?array $configs = null;

	/**
	 * @return ModuleConfig[]
	 */
	public function getConfigs(): array
	{
		if (self::$configs !== null)
		{
			return self::$configs;
		}

		$configs = [];
		foreach (\Bitrix\Main\ModuleManager::getInstalledModules() as $moduleId => $moduleData)
		{
			$config = Configuration::getInstance($moduleId)->get(self::CONFIGURATION_KEY);
			if ($config !== null && !empty($config['defaultNamespace']))
			{
				$configs[$moduleId] = new ModuleConfig(
					$moduleId,
					\Bitrix\Main\ModuleManager::getVersion($moduleId),
					$config['defaultNamespace'],
					$config['namespaces'] ?? [],
					$config['routes'] ?? [],
					$config['controllerProvider'] ?? null,
					$config['documentation'] ?? [],
					\Bitrix\Main\ModuleManager::getModificationDateTime($moduleId),
				);
			}
		}
		self::$configs = $configs;

		return self::$configs;
	}
}
