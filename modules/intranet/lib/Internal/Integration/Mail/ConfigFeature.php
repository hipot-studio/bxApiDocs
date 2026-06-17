<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Integration\Mail;

use Bitrix\Mail\Helper\Config\Feature;
use Bitrix\Main\Loader;

final class ConfigFeature
{
	public static function isMailboxConnectionRequestAvailable(): bool
	{
		if (!Loader::includeModule('mail'))
		{
			return false;
		}

		return Feature::isMailboxConnectionRequestAvailable();
	}
}
