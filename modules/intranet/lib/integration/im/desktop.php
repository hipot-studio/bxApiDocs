<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Integration\Im;

use Bitrix\Im\V2\Application\Context;
use Bitrix\Intranet\Internals\Trait\Singleton;
use Bitrix\Main\Loader;

class Desktop
{
	use Singleton;

	public function isDesktopRequest(): bool
	{
		return
			Loader::includeModule('im')
			&& Context::getCurrent()->isDesktop()
		;
	}
}
