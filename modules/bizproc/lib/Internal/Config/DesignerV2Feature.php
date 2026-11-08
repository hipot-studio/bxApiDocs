<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Config;

use Bitrix\Bizproc\Public\Feature\Designer\DesignerV2Flag;
use Bitrix\Main\Application;
use Bitrix\Main\Config\Feature;
use Bitrix\Main\Config\Feature\Context;
use Bitrix\Main\Config\Option;

/**
 * The switch of the new designer is the flag; the legacy option is kept in sync with it because
 * other modules (intranet menu and public pages, crm) still read the option directly.
 */
final class DesignerV2Feature
{
	private const MODULE_ID = 'bizproc';
	private const LEGACY_OPTION = 'designer_v2';

	public static function isEnabled(): bool
	{
		return Feature::isEnabled(DesignerV2Flag::class, new Context());
	}

	/**
	 * Checking the flag queries the ORM, and entity initialization sends events whose handlers include modules.
	 * While bizproc itself is being included by another module, that re-enters a module still in loading.
	 */
	public static function scheduleLegacyOptionSync(): void
	{
		Application::getInstance()->addBackgroundJob([self::class, 'syncLegacyOption']);
	}

	public static function syncLegacyOption(): void
	{
		$expected = self::isEnabled() ? 'Y' : 'N';
		if (Option::get(self::MODULE_ID, self::LEGACY_OPTION, 'N') === $expected)
		{
			return;
		}

		Option::set(self::MODULE_ID, self::LEGACY_OPTION, $expected);
		self::clearMenuCache();
	}

	private static function clearMenuCache(): void
	{
		global $CACHE_MANAGER;

		$CACHE_MANAGER->ClearByTag('bitrix24_left_menu');
		$CACHE_MANAGER->CleanDir('menu');
		\CBitrixComponent::clearComponentCache('bitrix:menu');
	}
}
