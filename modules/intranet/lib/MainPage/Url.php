<?php

namespace Bitrix\Intranet\MainPage;

use Bitrix\Main\Web\Uri;

class Url
{
	private const MAIN_PAGE_PUBLIC_PATH = '/welcome/';

	public function getPublic(): Uri
	{
		return new Uri(self::MAIN_PAGE_PUBLIC_PATH);
	}
}