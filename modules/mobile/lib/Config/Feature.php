<?php

declare(strict_types=1);

namespace Bitrix\Mobile\Config;

use Bitrix\Main\Loader;
use Bitrix\Main\ModuleManager;

/**
 * @deprecated Use Bitrix\Main\Config\Feature instead
 */
final class Feature
{
	/**
	 * @var array<string, string>|null
	 */
	private static array|null $flagModuleMap = null;

	public static function isEnabled(string|FeatureFlag $flag): bool
	{
		return self::resolve($flag)->isEnabled();
	}

	public static function isDisabled(string|FeatureFlag $flag): bool
	{
		return self::resolve($flag)->isDisabled();
	}

	public static function enable(string|FeatureFlag $flag): void
	{
		self::resolve($flag)->enable();
	}

	public static function disable(string|FeatureFlag $flag): void
	{
		self::resolve($flag)->disable();
	}

	private static function resolve(string|FeatureFlag $flag): FeatureFlag
	{
		if ($flag instanceof FeatureFlag)
		{
			return $flag;
		}

		self::loadFlags();

		// A consumer module may ask about a flag this version of mobile does not ship yet.
		$moduleId = self::$flagModuleMap[$flag] ?? null;

		if (
			$moduleId
			&& Loader::includeModule($moduleId)
			&& class_exists($flag)
			&& is_a($flag, FeatureFlag::class, true)
		)
		{
			return new $flag();
		}

		return self::getDisabledFlag();
	}

	private static function loadFlags(): void
	{
		if (self::$flagModuleMap !== null)
		{
			return;
		}

		self::$flagModuleMap = [];

		foreach (ModuleManager::getInstalledModules() as $moduleId => $moduleDesc)
		{
			$flags = \Bitrix\Main\Config\Configuration::getInstance($moduleId)->get('feature-flags');
			if (empty($flags) || !is_array($flags))
			{
				continue;
			}

			foreach ($flags as $className)
			{
				self::$flagModuleMap[$className] = $moduleId;
			}
		}
	}

	private static function getDisabledFlag(): FeatureFlag
	{
		return new class extends FeatureFlag
		{
			public function isEnabled(): bool
			{
				return false;
			}

			public function enable(): void
			{}

			public function disable(): void
			{}
		};
	}
}
