<?php

declare(strict_types=1);

namespace Bitrix\StaffTrackMobile\Public\Features;

use Bitrix\Main\Application;
use Bitrix\Main\Config\Feature\AbstractFlag;

final class OfficesTabFlag extends AbstractFlag
{
	private const MENU_LIST_CACHE_TAG = 'mobile_custom_menu';

	protected function onEnable(): void
	{
		$this->clearMenuListCache();
	}

	protected function onDisable(): void
	{
		$this->clearMenuListCache();
	}

	private function clearMenuListCache(): void
	{
		Application::getInstance()->getTaggedCache()->clearByTag(self::MENU_LIST_CACHE_TAG);
	}
}
