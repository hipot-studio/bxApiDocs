<?php
namespace Bitrix\Intranet\UI\LeftMenu\Preset;

use Bitrix\Intranet\Internal\Access\MainTool\AccessPolicy;
use Bitrix\Intranet\Public\Provider\MainTool\StateProvider;
use Bitrix\Intranet\UI\LeftMenu;
use Bitrix\Main;

class Manager
{
	protected static $presets = [];
	private static array $effectiveStates = [];

	public static function getEffectiveState(string $siteId, ?int $userId = null): ?array
	{
		return $userId === 0
			? self::getCommonMenuSettings($siteId)
			: self::getMenuSettingsForUser($siteId, $userId)
		;
	}

	public static function getMenuSettingsForUser(string $siteId, ?int $userId = null): ?array
	{
		if (!AccessPolicy::isFeatureEnabled())
		{
			return null;
		}

		$userId ??= static::getCurrentUserId();
		if ($userId <= 0)
		{
			return null;
		}

		if (!array_key_exists($userId, self::$effectiveStates[$siteId] ?? []))
		{
			self::$effectiveStates[$siteId][$userId] = (new StateProvider())->getMenuSettingsForUser($siteId, $userId);
		}

		return self::$effectiveStates[$siteId][$userId];
	}

	public static function getCommonMenuSettings(string $siteId): ?array
	{
		if (!AccessPolicy::isFeatureEnabled())
		{
			return null;
		}

		if (!array_key_exists('common', self::$effectiveStates[$siteId] ?? []))
		{
			self::$effectiveStates[$siteId]['common'] = (new StateProvider())->getCommonMenuSettings($siteId);
		}

		return self::$effectiveStates[$siteId]['common'];
	}

	protected static function getCurrentUserId(): int
	{
		return (int)Main\Engine\CurrentUser::get()->getId();
	}

	public static function clearCache(string $siteId): void
	{
		unset(self::$effectiveStates[$siteId], self::$presets[$siteId]);
		PresetAbstract::clearCache($siteId);
	}

	public static function getAllPresets(string $siteId): array
	{
		if (isset(self::$presets[$siteId]))
		{
			return self::$presets[$siteId];
		}

		$thisFile = new Main\IO\File(__FILE__);
		$thisDir = $thisFile->getDirectory();
		$childNames = [];
		foreach ($thisDir->getChildren() as $child)
		{
			if (!($child instanceof Main\IO\File) || $child->getExtension() !== 'php')
			{
				continue;
			}
			/*@var Main\IO\File $child */
			$name = 'Bitrix\Intranet\UI\LeftMenu\Preset\\'.str_replace('.'.$child->getExtension(), '', $child->getName());
			if (!( interface_exists($name) || class_exists($name)))
			{
				$res = include_once $child->getPhysicalPath();
			}

			$childNames[] = $name;
		}
		$classes = array_intersect(get_declared_classes(), $childNames);
		$result = [];
		foreach ($classes as $class)
		{
			try
			{
				$res = new \ReflectionClass($class);
				if ($res->implementsInterface(PresetInterface::class)
					&& !$res->isAbstract()
					&& $class::isAvailable()
				)
				{
					$preset = $res->newInstance($siteId);
					$result[$preset->getCode()] = $preset;
				}
			}
			catch(\ReflectionException $exception)
			{
			}
		}
		self::$presets[$siteId] = $result;
		return self::$presets[$siteId];
	}

	protected static function getCurrentPresetId(string $siteId): string
	{
		$menuSettings = self::getMenuSettingsForUser($siteId);
		if ($menuSettings !== null)
		{
			return $menuSettings['presetId'] ?? '';
		}

		//personal
		$presetId = \CUserOptions::GetOption('intranet', 'left_menu_preset_'.$siteId);
		//global
		if (!($presetId && array_key_exists($presetId, self::getAllPresets($siteId))))
		{
			$presetId = \COption::GetOptionString('intranet', 'left_menu_preset', '', $siteId);
		}
		return is_string($presetId) ? $presetId : '';
	}

	public static function getPreset($presetId = null, $siteId = null): PresetInterface
	{
		$siteId = (!is_null($siteId) ? $siteId :
			(defined('SITE_ID') ? SITE_ID : LeftMenu\Menu::getDefaultSiteId()));
		$presetId = $presetId ?: self::getCurrentPresetId($siteId);

		$presets = self::getAllPresets($siteId);
		return $presetId && array_key_exists($presetId, $presets) ? $presets[$presetId] : $presets['social'];
	}

	public static function hasOwnPreset(): bool
	{
		$siteId = defined('SITE_ID') ? SITE_ID : LeftMenu\Menu::getDefaultSiteId();
		$menuSettings = self::getMenuSettingsForUser($siteId);
		if ($menuSettings !== null)
		{
			return $menuSettings['hasPersonalChoice'];
		}

		 return \CUserOptions::GetOption('intranet', 'left_menu_preset_' . $siteId, 'N') !== 'N';
	}
}
