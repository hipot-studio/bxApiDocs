<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Config\Feature;

use Bitrix\Main\Config\Feature\AbstractFlag;

class CollaborationTopMenuFlag extends AbstractFlag
{
	public function enabledByDefault(): bool
	{
		return false;
	}

	protected function onEnable(): void
	{
		$this->clearMenuCache();
	}

	protected function onDisable(): void
	{
		$this->clearMenuCache();
	}

	protected function clearMenuCache(): void
	{
		global $CACHE_MANAGER;

		$CACHE_MANAGER->ClearByTag('bitrix24_left_menu');
		$CACHE_MANAGER->CleanDir('menu');
		\CBitrixComponent::clearComponentCache('bitrix:menu');
	}
}
