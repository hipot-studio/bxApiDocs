<?php

declare(strict_types=1);

namespace Bitrix\Mail\Helper\Config;

class Feature
{
	public static function isMailboxGridAvailable(): bool
	{
		return true;
	}
}
