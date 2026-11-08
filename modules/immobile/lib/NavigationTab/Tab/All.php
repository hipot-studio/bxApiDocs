<?php

namespace Bitrix\ImMobile\NavigationTab\Tab;

use Bitrix\Im\V2\Folder\Folder;
use Bitrix\ImMobile\NavigationTab\MessengerComponentTitle;
use Bitrix\ImMobile\Settings;
use Bitrix\Main\Localization\Loc;

class All extends BaseRecent
{
	use MessengerComponentTitle;

	public function __construct(private readonly ?Folder $folder = null)
	{
		parent::__construct();
	}

	public function isAvailable(): bool
	{
		return Settings::getImFeatures()?->isChatFolderAllAvailable ?? false;
	}

	public function isPreload(): bool
	{
		return true;
	}

	public function getId(): string
	{
		return 'all';
	}

	public function getTabTitle(): ?string
	{
		return $this->folder?->getTitle() ?? Loc::getMessage('IMMOBILE_NAVIGATION_TAB_ALL_TAB_TITLE');
	}

	public function getComponentCode(): string
	{
		return '';
	}

	protected function getComponentName(): string
	{
		return '';
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

	protected function isWidgetSupported(): bool
	{
		return true;
	}
}
