<?php

namespace Bitrix\Intranet\Integration\Landing\Vibe;

use Bitrix\Intranet\MainPage\Publisher;
use Bitrix\Landing\Vibe\Provider\AbstractVibeProvider;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\UI\Form\FormProvider;
use Bitrix\UI\Form\UrlProvider;

class MainPageProvider extends AbstractVibeProvider
{
	public function getTitle(): string
	{
		return Loc::getMessage('INTRANET_MAINPAGE_VIBE_PROVIDER_TITLE');
	}

	public function getViewTitle(): string
	{
		return Loc::getMessage('INTRANET_MAINPAGE_VIBE_PROVIDER_PAGE_TITLE');
	}

	public function isMainVibe(): bool
	{
		return true;
	}

	public function getUrlPublic(): string
	{
		return '/welcome';
	}

	public function isPublished(): bool
	{
		return (new Publisher())->isPublished();
	}

	public function onPublish(): void
	{
		(new Publisher())->publish();
	}

	public function onWithdraw(): void
	{
		(new Publisher())->withdraw();
	}
}