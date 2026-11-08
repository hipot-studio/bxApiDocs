<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Config\Feature;

use Bitrix\Intranet\Internal\Service\CollaborationMenuBalloon;
use Bitrix\Main\Config\Feature\AbstractFlag;
use Bitrix\Main\Config\Option;

class CollaborationWebMenuFlag extends AbstractFlag
{
	public function enabledByDefault(): bool
	{
		return false;
	}

	protected function onEnable(): void
	{
		if (Option::getRealValue('intranet', CollaborationMenuBalloon::ACTIVATION_TIMESTAMP_OPTION, '') === null)
		{
			Option::set('intranet', CollaborationMenuBalloon::ACTIVATION_TIMESTAMP_OPTION, (string)time());
		}

		$this->clearMenuCache();
	}

	protected function onDisable(): void
	{
		Option::delete('intranet', ['name' => CollaborationMenuBalloon::ACTIVATION_TIMESTAMP_OPTION, 'site_id' => '']);

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
