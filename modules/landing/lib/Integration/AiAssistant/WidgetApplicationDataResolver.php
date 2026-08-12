<?php

declare(strict_types=1);

namespace Bitrix\Landing\Integration\AiAssistant;

use Bitrix\Im\V2\Application\Config;
use Bitrix\Main\Loader;
use Throwable;

final class WidgetApplicationDataResolver
{
	public function resolve(): array
	{
		if (!Loader::includeModule('im'))
		{
			return [];
		}

		try
		{
			return (new Config())->jsonSerialize();
		}
		catch (Throwable)
		{
			return [];
		}
	}
}
