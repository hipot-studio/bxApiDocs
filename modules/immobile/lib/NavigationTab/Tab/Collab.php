<?php

namespace Bitrix\ImMobile\NavigationTab\Tab;

use Bitrix\ImMobile\NavigationTab\MessengerComponentTitle;
use Bitrix\ImMobile\Settings;
use Bitrix\Main\Localization\Loc;

class Collab extends BaseRecent
{
	use MessengerComponentTitle;

	public function isAvailable(): bool
	{
		return Settings::getImFeatures()?->collabAvailable ?? false;
	}

	public function isPreload(): bool
	{
		return false;
	}

	protected function getParams(): array
	{
		return [];
	}

	protected function getWidgetSettings(): array
	{
		return [
			'useSearch' => true,
			'preload' => $this->isPreload(),
			'titleParams' => [
				'useLargeTitleMode' => true,
				'text' => $this->getTitle(),
			],
		];
	}

	public function getId(): string
	{
		return 'collab';
	}

	protected function getTabTitle(): ?string
	{
		return Loc::getMessage('IMMOBILE_NAVIGATION_TAB_COLLAB_TAB_TITLE');
	}

	public function getComponentCode(): string
	{
		return '';
	}

	protected function getComponentName(): string
	{
		return 'im:collab-messenger';
	}

	protected function isWidgetSupported(): bool
	{
		return true;
	}
}
