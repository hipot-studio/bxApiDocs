<?php

namespace Bitrix\Intranet\Integration\Landing\Vibe;

use Bitrix\Main\Loader;
use Bitrix\Main\Web\Uri;
use Bitrix\Intranet\MainPage\Url;
use Bitrix\Landing;
use Bitrix\Landing\Vibe;

class MainPage
{
	private Vibe\Vibe $vibe;

	private const MODULE_NAME = 'intranet';
	private const EMBED_ID = 'mainpage';

	private static self $instance;

	public static function getInstance(): ?MainPage
	{
		if (!Loader::includeModule('landing'))
		{
			return null;
		}

		if (!isset(self::$instance))
		{
			self::$instance = new MainPage();
		}

		return self::$instance;
	}

	private function __construct()
	{
		$this->vibe = new Vibe\Vibe(self::MODULE_NAME, self::EMBED_ID);

		if (!$this->vibe->isRegistered())
		{
			$this->vibe->register(MainPageProvider::class);
		}
	}

	public function getVibe(): Vibe\Vibe
	{
		return $this->vibe;
	}
}