<?php

namespace Bitrix\Intranet\UI\LeftMenu\MenuItem;

use Bitrix\Intranet\MainPage;

class MainPageItem extends ItemSystem
{
	public function getCode(): string
	{
		return 'main';
	}

	public function getId(): string
	{
		return (new MainPage\Page)->getMenuId();
	}

	public function getSort(): ?int
	{
		return 0;
	}

	public function getLink(): ?string
	{
		return (new MainPage\Page)->getLink();
	}
}