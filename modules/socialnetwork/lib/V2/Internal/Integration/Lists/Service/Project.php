<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Integration\Lists\Service;

use Bitrix\Main\Loader;

class Project
{
	public function isAvailable(): bool
	{
		return Loader::includeModule('lists');
	}
}
