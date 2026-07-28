<?php

declare(strict_types=1);

namespace Bitrix\MessageService\Integration;

use Bitrix\ImOpenLines\Common;
use Bitrix\Main\Loader;

final class ImOpenLines
{
	public static function getContactCenterUrl(): string
	{
		if (!Loader::includeModule('imopenlines'))
		{
			return '';
		}

		return Common::getContactCenterPublicFolder();
	}
}
