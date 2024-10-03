<?php

namespace Bitrix\Intranet\MainPage;

use Bitrix\Main\Localization\Loc;

class Page
{
	public function getName(): string
	{
		return Loc::getMessage('INTRANET_MAIN_PAGE_NAME') ?? '';
	}

	public function getLink(): string
	{
		return (new Url)->getPublic();
	}

	public function getMenuId(): string
	{
		return 'main_page';
	}

	public function isAvailable(): bool
	{
		return (new Access)->canView();
	}

	public function enabled(): bool
	{
		return $this->isAvailable() && (new Publisher)->isPublished();
	}

	public function getSettingsPath(): string
	{
		return SITE_DIR . 'settings/configs/?page=mainpage';
	}
}