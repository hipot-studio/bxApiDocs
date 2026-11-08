<?php

declare(strict_types=1);

namespace Bitrix\StaffTrack\Public\Provider;

use Bitrix\Main\Localization\Loc;

class OfficesMenuItemProvider
{
	public const PAGE_PATH = 'stafftrack/offices/';
	private const MENU_ITEM_ID = 'menu_stafftrack_offices';

	public function getMenuItem(): array
	{
		if (!$this->isFeatureAvailable())
		{
			return [];
		}

		$pageUrl = $this->getSiteDir() . self::PAGE_PATH;
		$openSlider = 'BX.SidePanel.Instance.open(\'' . \CUtil::JSEscape($pageUrl) . '\', { cacheable: false })';

		return [
			Loc::getMessage('STAFFTRACK_OFFICES_MENU_ITEM_TITLE'),
			$pageUrl,
			[],
			[
				'menu_item_id' => self::MENU_ITEM_ID,
				'real_link' => '',
				'onclick' => $openSlider,
			],
			'',
		];
	}

	protected function isFeatureAvailable(): bool
	{
		return (new OfficesAvailabilityProvider())->isAvailable();
	}

	protected function getSiteDir(): string
	{
		return defined('SITE_DIR') ? SITE_DIR : '/';
	}
}
